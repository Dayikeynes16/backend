<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use App\Notifications\SaleCancellationRequested;
use App\Notifications\SaleCancellationResolved;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Quién se entera de qué en el circuito de cancelaciones.
 *
 * Vive aparte porque el circuito tiene cinco puntos de entrada —mesa de trabajo
 * de sucursal, de caja, y la API del hub para las tres acciones— y la regla de
 * a quién avisar es la misma en todos. Repetirla cinco veces es la forma
 * habitual de que una de las cinco se quede sin avisar.
 *
 * Nada de esto puede tumbar la operación: la solicitud o la cancelación ya
 * están guardadas cuando se llama aquí. Un fallo al notificar se registra y se
 * sigue.
 */
class SaleCancellationNotifier
{
    /**
     * Los administradores de la sucursal de la venta.
     *
     * El aislamiento es doble a propósito: `branch_id` acota la sucursal y
     * `tenant_id` la empresa. Con el `branch_id` bastaría —es único global—
     * pero dejarlo explícito evita que un cambio futuro en el modelo de
     * sucursales abra una fuga silenciosa entre empresas.
     *
     * @return Collection<int, User>
     */
    private function branchAdmins(Sale $sale)
    {
        return User::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('branch_id', $sale->branch_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin-sucursal'))
            ->get();
    }

    /** Un cajero pidió cancelar: avisa a los administradores de su sucursal. */
    public function requested(Sale $sale, User $requestedBy, ?string $reason): void
    {
        $this->guard(function () use ($sale, $requestedBy, $reason) {
            $admins = $this->branchAdmins($sale);

            if ($admins->isEmpty()) {
                // Sin administrador asignado la solicitud no tiene quien la
                // resuelva. No es un error de este código, pero conviene que
                // quede escrito: si no, se lee como "el aviso se perdió".
                Log::info('Solicitud de cancelación sin administrador de sucursal a quien avisar', [
                    'sale_id' => $sale->id,
                    'branch_id' => $sale->branch_id,
                ]);

                return;
            }

            foreach ($admins as $admin) {
                $admin->notify(new SaleCancellationRequested($sale, $requestedBy->name, $reason));
            }
        }, 'requested', $sale);
    }

    /**
     * El administrador resolvió: avisa al cajero que la pidió.
     *
     * `cancel_requested_by` se limpia al resolver, así que hay que leerlo antes
     * y pasarlo aquí.
     *
     * @param  'approved'|'rejected'  $outcome
     */
    public function resolved(Sale $sale, ?int $requestedByUserId, string $outcome, User $resolvedBy, ?string $reason = null): void
    {
        $this->guard(function () use ($sale, $requestedByUserId, $outcome, $resolvedBy, $reason) {
            if (! $requestedByUserId || $requestedByUserId === $resolvedBy->id) {
                // Si el propio administrador canceló sin que nadie lo pidiera,
                // no hay a quién avisar.
                return;
            }

            $requester = User::query()
                ->where('tenant_id', $sale->tenant_id)
                ->find($requestedByUserId);

            $requester?->notify(new SaleCancellationResolved($sale, $outcome, $resolvedBy->name, $reason));
        }, 'resolved', $sale);
    }

    private function guard(callable $fn, string $step, Sale $sale): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Aviso de cancelación ({$step}) falló", [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
