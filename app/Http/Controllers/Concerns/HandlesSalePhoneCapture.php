<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\Customers\CustomerAssignmentPreview;
use App\Services\Customers\ResolveCustomerByPhone;
use App\Services\PhoneNormalizer;
use App\Services\WhatsappMessageService;
use Illuminate\Http\Request;

/**
 * Captura de teléfono desde una venta: resuelve el cliente de la sucursal
 * (creándolo sin nombre si hace falta) y lo asocia a la venta.
 *
 * Compartido por Caja, Sucursal y el hub, que exponen el mismo endpoint.
 *
 * Política: la asignación es automática, salvo que cambie el total de la venta
 * o la deje completada — ahí se devuelve `requires_confirmation` con el detalle
 * del impacto para que el usuario decida. Capturar un teléfono nunca debe
 * alterar en silencio una venta ya cobrada.
 *
 * `skip_assign` es la salida de escape: guarda el número en la venta como se
 * hacía antes, sin tocar clientes, para que rechazar la asociación no impida
 * mandar la nota por WhatsApp.
 */
trait HandlesSalePhoneCapture
{
    /**
     * @return array<string, mixed>
     */
    protected function capturePhoneForSale(
        Sale $sale,
        string $phone,
        bool $confirmed,
        bool $skipAssign,
        WhatsappMessageService $whatsapp,
        ResolveCustomerByPhone $resolver,
        AssignCustomerToSale $assigner,
    ): array {
        if ($skipAssign) {
            $sale->update(['contact_phone' => PhoneNormalizer::normalize($phone)]);

            return $whatsapp->linkForSale($sale->fresh());
        }

        $resolution = $resolver->execute($phone, $sale->branch_id, $sale->tenant_id);
        $customer = $resolution->customer;

        $customerPayload = [
            'id' => $customer->id,
            'name' => $customer->name,
            'name_pending' => (bool) $customer->name_pending,
            'phone' => $customer->phone,
        ];

        // Ya asignado a este mismo cliente: nada que hacer más que devolver el link.
        if ($sale->customer_id === $customer->id) {
            return [
                ...$whatsapp->linkForSale($sale->fresh()),
                'customer' => $customerPayload,
                'customer_created' => $resolution->wasCreated,
            ];
        }

        $preview = CustomerAssignmentPreview::for($sale, $customer);

        if (($preview['changes_total'] || $preview['would_complete']) && ! $confirmed) {
            return [
                'requires_confirmation' => true,
                'customer' => $customerPayload,
                'customer_created' => $resolution->wasCreated,
                'preview' => $preview,
            ];
        }

        $assigner->execute($sale, $customer->id, $sale->branch_id);

        return [
            ...$whatsapp->linkForSale($sale->fresh()),
            'customer' => $customerPayload,
            'customer_created' => $resolution->wasCreated,
            'preview' => $preview,
        ];
    }

    /**
     * Reglas compartidas por los tres canales.
     *
     * @return array<string, mixed>
     */
    protected function validateSalePhoneCapture(Request $request): array
    {
        return $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
            'confirmed' => ['nullable', 'boolean'],
            'skip_assign' => ['nullable', 'boolean'],
        ], [
            'phone.regex' => 'El teléfono debe tener 10 dígitos.',
            'phone.required' => 'Ingresa un teléfono.',
        ]);
    }
}
