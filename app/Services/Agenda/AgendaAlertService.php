<?php

namespace App\Services\Agenda;

use App\Models\ApiKey;
use App\Models\CashRegisterShift;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Metrics\CollectionMetrics;

class AgendaAlertService
{
    public function __construct(private readonly CollectionMetrics $collection) {}

    /**
     * Alertas derivadas (solo lectura) para el usuario, acotadas a su(s)
     * sucursal(es) visibles. NO escribe en BD.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for(User $user): array
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $branchId = $isCompanyAdmin ? null : $user->branch_id;
        $tenantId = $user->tenant_id;

        return array_merge(
            $this->accountsPayable($tenantId, $branchId),
            $this->overdueCredit($tenantId, $branchId),
            $this->openStaleShifts($tenantId, $branchId),
            $this->expiringApiKeys($tenantId, $branchId),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function accountsPayable(int $tenantId, ?int $branchId): array
    {
        return Purchase::query()
            ->where('amount_pending', '>', 0)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('provider:id,name')
            ->orderBy('purchased_at')
            ->limit(50)
            ->get()
            ->map(fn (Purchase $p) => [
                'key' => "payable-{$p->id}",
                'source' => 'accounts_payable',
                'title' => 'Pago a proveedor: '.($p->provider?->name ?? 'proveedor'),
                'detail' => "Saldo de la compra {$p->folio}",
                'amount' => (float) $p->amount_pending,
                'due_at' => optional($p->purchased_at)->toIso8601String(),
                'severity' => $p->purchased_at && $p->purchased_at->lt(now()->subDays(30)) ? 'high' : 'normal',
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function overdueCredit(int $tenantId, ?int $branchId): array
    {
        $rows = $this->collection->receivablesTable($branchId, $tenantId, 50);

        return collect($rows)
            ->filter(fn ($r) => ($r['age_days'] ?? 0) > 30)
            ->map(fn ($r) => [
                'key' => "credit-{$r['id']}",
                'source' => 'overdue_credit',
                'title' => 'Cobrar a '.$r['name'],
                'detail' => "Debe hace {$r['age_days']} días",
                'amount' => (float) $r['balance'],
                'due_at' => null,
                'severity' => ($r['age_days'] ?? 0) > 60 ? 'high' : 'normal',
                'phone' => $r['phone'] ?? null,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function openStaleShifts(int $tenantId, ?int $branchId): array
    {
        return CashRegisterShift::query()
            ->whereNull('closed_at')
            ->where('opened_at', '<', now()->startOfDay())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get()
            ->map(fn (CashRegisterShift $s) => [
                'key' => "shift-{$s->id}",
                'source' => 'open_shift',
                'title' => 'Turno sin cerrar',
                'detail' => 'Abierto desde '.$s->opened_at->format('d/m H:i'),
                'amount' => null,
                'due_at' => $s->opened_at->toIso8601String(),
                'severity' => 'high',
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function expiringApiKeys(int $tenantId, ?int $branchId): array
    {
        return ApiKey::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get()
            ->map(fn (ApiKey $k) => [
                'key' => "apikey-{$k->id}",
                'source' => 'api_key',
                'title' => 'API Key por expirar: '.$k->name,
                'detail' => 'Expira '.$k->expires_at->format('d/m/Y'),
                'amount' => null,
                'due_at' => $k->expires_at->toIso8601String(),
                'severity' => 'normal',
            ])->all();
    }
}
