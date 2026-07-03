<?php

namespace App\Console\Commands;

use App\Enums\AiDraftStatus;
use App\Models\AiCategoryDraft;
use App\Models\AiExpenseDraft;
use App\Models\AiPurchaseDraft;
use App\Models\AssistantDraft;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Expira borradores de IA vencidos y borra sus archivos privados asociados.
 *
 * Cubre la tabla general `assistant_drafts` (por `expires_at`) y las tablas
 * específicas heredadas (`ai_expense_drafts`, `ai_purchase_drafts`,
 * `ai_category_drafts`) que hasta ahora nunca se limpiaban pese a existir el
 * TTL en config (deuda técnica). Idempotente: sólo toca estados no terminales.
 *
 * Se ejecuta cada hora vía el scheduler (ver bootstrap/app.php). En producción
 * requiere un cron `php artisan schedule:run` cada minuto.
 */
class ExpireAiDraftsCommand extends Command
{
    protected $signature = 'ai:expire-drafts';

    protected $description = 'Marca como expirados los borradores de IA vencidos y borra sus archivos.';

    /**
     * Estados no terminales que aún pueden expirar.
     */
    private const EXPIRABLE = [
        AiDraftStatus::Pending->value,
        AiDraftStatus::Ready->value,
        AiDraftStatus::Failed->value,
    ];

    public function handle(): int
    {
        $disk = (string) config('expenses.disk', 'local');
        $total = 0;

        // 1) Tabla general del asistente — vence por expires_at explícito.
        $total += $this->expire(
            AssistantDraft::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', self::EXPIRABLE)
                ->where('expires_at', '<', now()),
            $disk,
        );

        // 2) Tablas heredadas — vencen por created_at + TTL (ai.expenses.draft_ttl_hours).
        $legacyCutoff = now()->subHours((int) config('ai.expenses.draft_ttl_hours', 24));
        foreach ([AiExpenseDraft::class, AiPurchaseDraft::class, AiCategoryDraft::class] as $model) {
            $total += $this->expire(
                $model::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->whereIn('status', self::EXPIRABLE)
                    ->where('created_at', '<', $legacyCutoff),
                $disk,
            );
        }

        $this->info("Borradores expirados: {$total}.");

        return self::SUCCESS;
    }

    private function expire(Builder $query, string $disk): int
    {
        $count = 0;

        $query->chunkById(200, function ($drafts) use ($disk, &$count) {
            foreach ($drafts as $draft) {
                $this->purgeFiles($draft, $disk);
                $draft->update(['status' => AiDraftStatus::Expired->value]);
                $count++;
            }
        });

        return $count;
    }

    private function purgeFiles(Model $draft, string $disk): void
    {
        $storage = Storage::disk($disk);

        foreach ($draft->attachment_paths ?? [] as $entry) {
            if (isset($entry['path'])) {
                $storage->delete($entry['path']);
            }
        }

        if (! empty($draft->audio_path)) {
            $storage->delete($draft->audio_path);
        }
    }
}
