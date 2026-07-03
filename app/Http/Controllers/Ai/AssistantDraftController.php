<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AiDraftStatus;
use App\Http\Controllers\Controller;
use App\Models\AssistantDraft;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Confirma o cancela un borrador del asistente. Es el ÚNICO punto que ejecuta la
 * escritura definitiva — nunca una tool. Requiere una acción explícita de la UI.
 *
 * Seguridad:
 *  - Route-model-binding con TenantScope aísla por tenant; se verifica además el
 *    dueño (user_id) para prevenir IDOR dentro del tenant.
 *  - Se re-valida TODO el payload editado (nunca se confía en lo guardado).
 *  - lockForUpdate + status=ready + expires_at hacen el consumo single-use e
 *    idempotente frente a doble clic o peticiones repetidas.
 */
class AssistantDraftController extends Controller
{
    public function __construct(
        private readonly DraftConfirmerRegistry $confirmers,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function confirm(Request $request, AssistantDraft $draft): JsonResponse
    {
        $user = Auth::user();

        if ($draft->user_id !== $user->id || $draft->tenant_id !== app('tenant')->id) {
            return response()->json(['message' => 'Borrador no encontrado.'], 404);
        }

        $confirmer = $this->confirmers->for($draft->type);

        if (! $confirmer->authorize($user, $draft)) {
            return response()->json(['message' => 'No tienes permiso para confirmar este borrador.'], 403);
        }

        $validated = $request->validate($confirmer->rules($user), $confirmer->messages());

        $result = null;
        DB::transaction(function () use (&$result, $draft, $user, $confirmer, $validated) {
            $locked = AssistantDraft::query()
                ->whereKey($draft->getKey())
                ->where('user_id', $user->id)
                ->where('status', AiDraftStatus::Ready->value)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return; // ya consumido, cancelado o expirado
            }

            $result = $confirmer->confirm($locked, $user, $validated);
        });

        if ($result === null) {
            return response()->json([
                'message' => 'Este borrador ya no está disponible (confirmado, cancelado o expirado).',
            ], 409);
        }

        return response()->json([
            'message' => $result->message,
            'result_id' => $result->record->getKey(),
            'card' => $result->card,
        ]);
    }

    public function cancel(Request $request, AssistantDraft $draft): JsonResponse
    {
        $user = Auth::user();

        if ($draft->user_id !== $user->id || $draft->tenant_id !== app('tenant')->id) {
            return response()->json(['message' => 'Borrador no encontrado.'], 404);
        }

        if ($draft->status === AiDraftStatus::Consumed) {
            return response()->json(['message' => 'Este borrador ya fue confirmado.'], 409);
        }

        $this->drafts->markCancelled($draft);

        return response()->json(['message' => 'Borrador cancelado.']);
    }
}
