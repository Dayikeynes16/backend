<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Services\Ai\Assistant\AssistantOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Asistente para admin-sucursal. La lógica es idéntica al de Empresa; lo que
 * limita lo que puede consultar es el `branch_id` que cada Tool reescribe en
 * `AbstractAssistantTool::resolveBranch()` cuando el usuario tiene rol
 * admin-sucursal. Aquí sólo cambia el layout/página de Inertia.
 */
class AsistenteController extends Controller
{
    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $sessions = AiAssistantSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'title', 'message_count', 'last_message_at']);

        $sessionId = $request->integer('session') ?: $sessions->first()?->id;
        $activeSession = $sessionId
            ? AiAssistantSession::query()->where('user_id', $user->id)->find($sessionId)
            : null;

        $messages = $activeSession
            ? $activeSession->messages()
                ->orderBy('id')
                ->get()
                ->map(fn (AiAssistantMessage $m) => $this->serializeMessage($m))
                ->values()
                ->all()
            : [];

        return Inertia::render('Sucursal/Asistente', [
            'sessions' => $sessions,
            'activeSessionId' => $activeSession?->id,
            'messages' => $messages,
            'budget' => [
                'remaining_cents' => $this->orchestrator->budgetRemainingCents($tenant),
                'cap_cents' => $tenant->ai_monthly_budget_cents
                    ?? (int) config('ai.assistant.default_monthly_budget_cents', 5000),
            ],
        ]);
    }

    public function createSession(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $session = AiAssistantSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'title' => null,
            'message_count' => 0,
        ]);

        return redirect()->route('sucursal.asistente', [
            'tenant' => $tenant->slug,
            'session' => $session->id,
        ]);
    }

    public function sendMessage(Request $request, AiAssistantSession $session): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($session->user_id !== $user->id || $session->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        $validated = $request->validate([
            'content' => [
                'required', 'string',
                'max:'.config('ai.assistant.max_input_text_length', 2000),
            ],
        ]);

        $userKey = 'ai-assistant:user:'.$user->id;
        $tenantKey = 'ai-assistant:tenant:'.$tenant->id;
        $perHour = (int) config('ai.assistant.rate_limit_per_user_per_hour', 60);
        $perDay = (int) config('ai.assistant.rate_limit_per_tenant_per_day', 1000);

        if (RateLimiter::tooManyAttempts($userKey, $perHour)) {
            return response()->json([
                'message' => 'Has excedido el límite por hora. Intenta de nuevo más tarde.',
            ], 429);
        }
        if (RateLimiter::tooManyAttempts($tenantKey, $perDay)) {
            return response()->json([
                'message' => 'Tu empresa alcanzó el límite diario del asistente.',
            ], 429);
        }

        try {
            $this->orchestrator->assertWithinBudget($tenant);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'budget_exhausted') {
                return response()->json([
                    'message' => 'Se agotó el presupuesto de IA de este mes. Contacta a soporte para ampliarlo.',
                ], 402);
            }
            throw $e;
        }

        RateLimiter::hit($userKey, 3600);
        RateLimiter::hit($tenantKey, 86400);

        try {
            $result = $this->orchestrator->handleUserMessage(
                $tenant,
                $user,
                $session,
                $validated['content'],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude procesar tu mensaje. Intenta de nuevo.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        $newMessages = $session->messages()
            ->where('id', '>', $result['message']->id - 20)
            ->where('id', '<=', $result['message']->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AiAssistantMessage $m) => $this->serializeMessage($m))
            ->values()
            ->all();

        return response()->json([
            'session_id' => $session->id,
            'messages' => $newMessages,
            'cards' => $result['cards'],
            'budget_remaining_cents' => $result['budget_remaining_cents'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(AiAssistantMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'tool_name' => $m->tool_name,
            'tool_status' => $m->tool_status,
            'tool_result' => $m->role === 'tool' ? $m->tool_result : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
