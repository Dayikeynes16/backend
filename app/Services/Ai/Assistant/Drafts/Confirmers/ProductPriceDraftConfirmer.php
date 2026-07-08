<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de cambio de precio base. Solo admin-sucursal y solo
 * productos de SU sucursal; actualiza únicamente el campo `price` (D7).
 */
final class ProductPriceDraftConfirmer implements DraftConfirmer
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::PriceChange;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return $user->hasRole('admin-sucursal') || $user->hasRole('superadmin');
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

        return [
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where(function ($q) use ($tenantId, $user) {
                    $q->where('tenant_id', $tenantId);
                    if ($user->branch_id) {
                        $q->where('branch_id', $user->branch_id);
                    }
                }),
            ],
            'new_price' => 'required|numeric|min:0.01|max:99999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Selecciona el producto.',
            'product_id.exists' => 'El producto no es válido o no pertenece a tu sucursal.',
            'new_price.min' => 'El precio debe ser mayor a 0.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $product = Product::query()->whereKey((int) $validated['product_id'])->firstOrFail();

        // Defensa en profundidad: jamás un producto de otra sucursal.
        if ($product->branch_id !== $user->branch_id) {
            abort(403);
        }

        $oldPrice = (float) $product->price;
        $newPrice = round((float) $validated['new_price'], 2);

        $product->update(['price' => $newPrice]);

        $this->drafts->markConsumed($draft, $product);

        $message = 'Precio de "'.$product->name.'" actualizado: $'.number_format($oldPrice, 2).' → $'.number_format($newPrice, 2).'.';

        return new DraftConfirmationResult(
            record: $product,
            message: $message,
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'price_change',
                'status' => 'consumed',
                'result_id' => $product->id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ],
        );
    }
}
