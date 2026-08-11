<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Busca un cliente por teléfono dentro de una sucursal y, si no existe, lo crea
 * sin nombre (`name_pending`). Es el único punto del sistema que decide si un
 * teléfono corresponde a un cliente nuevo o a uno existente.
 *
 * La cartera es **por sucursal**: el mismo número puede existir como cliente
 * independiente en dos sucursales del mismo tenant, y así debe seguir siendo.
 *
 * El advisory lock por sucursal evita que dos cajas capturando el mismo número
 * a la vez creen dos clientes. Es el mismo patrón que usa `OrderController`.
 */
class ResolveCustomerByPhone
{
    public function execute(string $phone, int $branchId, int $tenantId): CustomerResolution
    {
        $normalized = PhoneNormalizer::normalize($phone);

        // No basta con que tenga dígitos: dar de alta clientes con '344' o
        // '+8556' llena la cartera de registros inservibles y expone a
        // fusiones accidentales cuando esa basura coincide.
        if (! PhoneNormalizer::isPlausible($normalized)) {
            throw new InvalidArgumentException('El teléfono no es válido.');
        }

        return DB::transaction(function () use ($normalized, $branchId, $tenantId) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);

            // Sin filtro de status: un cliente inactivo con ese número sigue
            // siendo ese cliente — reactivarlo o no es decisión del usuario,
            // pero duplicarlo nunca es correcto.
            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('phone', $normalized)
                ->first();

            if ($existing) {
                return new CustomerResolution($existing, false);
            }

            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'name' => self::placeholderName($normalized),
                'name_pending' => true,
                'phone' => $normalized,
                'status' => 'active',
            ]);

            return new CustomerResolution($customer, true);
        });
    }

    /**
     * Nombre provisional legible para un cliente del que solo sabemos el número.
     */
    public static function placeholderName(string $e164): string
    {
        return 'Cliente '.PhoneNormalizer::displayLocal($e164);
    }
}
