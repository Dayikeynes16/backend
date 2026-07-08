<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AiDraftStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
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
 * Chat del asistente conversacional compartido por Empresa, Sucursal y la
 * mini-app. Lo que limita el alcance de cada rol NO está aquí: cada Tool
 * reescribe branch_id en AbstractAssistantTool::resolveBranch() y los
 * confirmers re-validan. El controller que usa el trait define la página
 * Inertia y la ruta de redirect, y debe declarar la propiedad
 * `private readonly AssistantOrchestrator $orchestrator` en su constructor.
 */
trait HandlesAssistantChat
{
    abstract protected function inertiaPage(): string;

    abstract protected function indexRouteName(): string;

    /**
     * Pantalla principal del asistente. Lista las sesiones del usuario y carga
     * los mensajes de la sesión activa (la última o la seleccionada por query
     * string ?session=ID).
     */
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

        return Inertia::render($this->inertiaPage(), [
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

    /**
     * Crea una sesión nueva y redirige a /asistente?session=ID.
     */
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

        return redirect()->route($this->indexRouteName(), [
            'tenant' => $tenant->slug,
            'session' => $session->id,
        ]);
    }

    /**
     * Envía un mensaje del usuario y devuelve la respuesta del asistente.
     */
    public function sendMessage(Request $request, AiAssistantSession $session): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($session->user_id !== $user->id || $session->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        $validated = $request->validate([
            'content' => [
                'nullable', 'string',
                'max:'.config('ai.assistant.max_input_text_length', 2000),
            ],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (blank($validated['content'] ?? null) && ! $request->hasFile('attachment')) {
            return response()->json(['message' => 'Escribe un mensaje o adjunta un recibo.'], 422);
        }

        // Rate limit por usuario (hora) y por tenant (día).
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
                (string) ($validated['content'] ?? ''),
                $request->hasFile('attachment') ? [$request->file('attachment')] : [],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude procesar tu mensaje. Intenta de nuevo.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        // Devolvemos los últimos mensajes nuevos (user + assistant + tool) para
        // que el frontend los agregue al thread sin recargar.
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
        $toolResult = $m->role === 'tool' ? $m->tool_result : null;

        // Las cards de borrador se guardan congeladas al prepararse; al
        // recargar el hilo se refleja el estado ACTUAL del draft para que un
        // borrador ya confirmado se vea como "registrado" (y uno cancelado o
        // expirado no vuelva a ofrecer el botón de confirmar).
        if (is_array($toolResult) && isset($toolResult['draft_id'], $toolResult['draft_type'])) {
            $draft = AssistantDraft::query()->find($toolResult['draft_id']);
            $status = $draft?->status ?? AiDraftStatus::Expired;
            if ($status === AiDraftStatus::Ready && $draft?->expires_at?->isPast()) {
                $status = AiDraftStatus::Expired;
            }
            $toolResult['status'] = $status->value;
            $toolResult['result_id'] = $draft?->result_id;
        }

        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'tool_name' => $m->tool_name,
            'tool_status' => $m->tool_status,
            'tool_result' => $toolResult,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
