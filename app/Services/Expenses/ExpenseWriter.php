<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ExpenseAttachmentService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Punto único de creación de gastos (dominio). Extraído de GastoController@store
 * para que tanto la captura manual/IA por los controllers como la confirmación
 * de un borrador del asistente pasen por EXACTAMENTE la misma lógica: crear el
 * Expense, adjuntar archivos (subidos o movidos desde un borrador) y auditar.
 *
 * El caller es responsable de la validación y de forzar tenant/branch/user; este
 * servicio confía en que $data ya viene validado.
 */
final class ExpenseWriter
{
    public function __construct(
        private readonly ExpenseAttachmentService $attachments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     branch_id: int,
     *     expense_subcategory_id: int,
     *     concept: string,
     *     amount: float|string,
     *     payment_method?: string|null,
     *     expense_at: Carbon,
     *     description?: string|null,
     *     cash_register_shift_id?: int|null,
     * }  $data
     * @param  iterable<UploadedFile>  $uploadedFiles  archivos nuevos subidos en el request
     * @param  array<int, array<string, mixed>>  $draftAttachmentPaths  metadata de archivos ya en disco a mover
     * @param  Closure(Expense):void|null  $afterCreate  hook dentro de la transacción (p.ej. consumir el draft)
     */
    public function create(
        Tenant $tenant,
        User $user,
        array $data,
        iterable $uploadedFiles = [],
        array $draftAttachmentPaths = [],
        ?Closure $afterCreate = null,
    ): Expense {
        $files = is_array($uploadedFiles) ? $uploadedFiles : iterator_to_array($uploadedFiles);

        $expense = DB::transaction(function () use ($tenant, $user, $data, $files, $draftAttachmentPaths, $afterCreate) {
            $expense = Expense::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $data['branch_id'],
                'expense_subcategory_id' => $data['expense_subcategory_id'],
                'cash_register_shift_id' => $data['cash_register_shift_id'] ?? null,
                'user_id' => $user->id,
                'concept' => $data['concept'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? null,
                'expense_at' => $data['expense_at'],
                'description' => $data['description'] ?? null,
            ]);

            if ($files !== []) {
                $this->attachments->attach($expense, $files, $user->id);
            }

            if ($draftAttachmentPaths !== []) {
                $this->attachments->attachFromDraftPaths($expense, $draftAttachmentPaths, $user->id);
            }

            if ($afterCreate !== null) {
                $afterCreate($expense);
            }

            return $expense;
        });

        $this->audit->logCreated($expense);

        return $expense;
    }

    /**
     * Combina la fecha capturada (solo día) con la hora actual del registro.
     */
    public static function buildExpenseAt(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.now()->format('H:i:s'));
    }
}
