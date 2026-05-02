<?php

namespace Tests\Unit\Support;

use App\Support\SaleItemMath;
use Tests\TestCase;

/**
 * Tests del contrato de cálculo de peso real, costo y precio preferencial.
 *
 * Cubre los casos canónicos del refactor:
 *   - Peso real: 1×0.5kg=0.5kg, 2×0.5kg=1kg, 1×5kg=5kg, 3×5kg=15kg, 2kg
 *     libres=2kg, mix=8kg.
 *   - Costo: 1 presentación de 5kg con costo $120/kg → $600 (no $120).
 *   - Precio preferencial: $135/kg en presentación de 5kg → unit_price $675
 *     (no $135). Subtotal = unit_price × quantity se mantiene.
 *   - Restauración al desasignar: viene de presentation_snapshot.price si
 *     existe, sino del catálogo.
 */
class SaleItemMathTest extends TestCase
{
    // ─── realContent: presentaciones con peso ───────────────────────────────

    public function test_real_content_one_half_kilo_presentation_is_half_kilo(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 0.500, unit: 'kg');

        $this->assertSame(['amount' => 0.5, 'unit' => 'kg', 'kind' => 'weight'], SaleItemMath::realContent($item));
    }

    public function test_real_content_two_half_kilo_presentations_is_one_kilo(): void
    {
        $item = $this->presentationItem(quantity: 2, content: 0.500, unit: 'kg');

        $this->assertSame(['amount' => 1.0, 'unit' => 'kg', 'kind' => 'weight'], SaleItemMath::realContent($item));
    }

    public function test_real_content_one_five_kilo_presentation_is_five_kilos(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg');

        $this->assertSame(['amount' => 5.0, 'unit' => 'kg', 'kind' => 'weight'], SaleItemMath::realContent($item));
    }

    public function test_real_content_three_five_kilo_presentations_is_fifteen_kilos(): void
    {
        $item = $this->presentationItem(quantity: 3, content: 5.000, unit: 'kg');

        $this->assertSame(['amount' => 15.0, 'unit' => 'kg', 'kind' => 'weight'], SaleItemMath::realContent($item));
    }

    public function test_real_content_normalizes_grams_to_kilos(): void
    {
        // 2 presentaciones × 500 g = 1000 g → 1 kg.
        $item = $this->presentationItem(quantity: 2, content: 500, unit: 'g');

        $this->assertSame(['amount' => 1.0, 'unit' => 'kg', 'kind' => 'weight'], SaleItemMath::realContent($item));
    }

    // ─── realContent: peso libre y mix ──────────────────────────────────────

    public function test_real_content_two_kilos_loose_is_two_kilos(): void
    {
        $item = ['quantity' => 2.000, 'quantity_unit' => 'kg', 'unit_type' => 'kg', 'presentation_snapshot' => null];

        $this->assertSame(['amount' => 2.0, 'unit' => 'kg', 'kind' => 'weight'], SaleItemMath::realContent($item));
    }

    public function test_total_weight_across_mixed_lines_sums_correctly(): void
    {
        // 2 kg libres + 1 presentación de 5 kg + 2 presentaciones de 0.5 kg = 8 kg.
        $loose2kg = ['quantity' => 2.000, 'quantity_unit' => 'kg', 'unit_type' => 'kg', 'presentation_snapshot' => null];
        $fiveKg = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg');
        $halfKgX2 = $this->presentationItem(quantity: 2, content: 0.500, unit: 'kg');

        $total = collect([$loose2kg, $fiveKg, $halfKgX2])
            ->map(fn ($i) => SaleItemMath::realContent($i)['amount'] ?? 0)
            ->sum();

        $this->assertSame(8.0, (float) $total);
    }

    // ─── realContent: piezas ────────────────────────────────────────────────

    public function test_real_content_piece_only_presentation_is_pieces(): void
    {
        $item = $this->presentationItem(quantity: 3, content: 1, unit: 'pieza');

        $this->assertSame(['amount' => 3.0, 'unit' => 'piece', 'kind' => 'piece'], SaleItemMath::realContent($item));
    }

    // ─── lineCost: costo por unidad base × peso real ────────────────────────

    public function test_line_cost_for_five_kilo_presentation_at_120_per_kg_is_600(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg', costAtSale: 120);

        $this->assertSame(600.0, SaleItemMath::lineCost($item));
    }

    public function test_line_cost_for_half_kilo_x2_at_120_per_kg_is_120(): void
    {
        // 2 × 0.5 kg = 1 kg × $120 = $120.
        $item = $this->presentationItem(quantity: 2, content: 0.500, unit: 'kg', costAtSale: 120);

        $this->assertSame(120.0, SaleItemMath::lineCost($item));
    }

    public function test_line_cost_for_loose_kilo_uses_quantity_directly(): void
    {
        $item = ['quantity' => 2.000, 'quantity_unit' => 'kg', 'unit_type' => 'kg', 'presentation_snapshot' => null, 'cost_price_at_sale' => 120];

        $this->assertSame(240.0, SaleItemMath::lineCost($item));
    }

    public function test_line_cost_for_piece_only_presentation_uses_quantity(): void
    {
        $item = $this->presentationItem(quantity: 3, content: 1, unit: 'pieza', costAtSale: 50);

        $this->assertSame(150.0, SaleItemMath::lineCost($item));
    }

    public function test_line_cost_returns_zero_when_cost_is_null(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg', costAtSale: null);

        $this->assertSame(0.0, SaleItemMath::lineCost($item));
    }

    // ─── unitPriceForBasePrice: precio preferencial por kg ──────────────────

    public function test_preferential_price_for_five_kilo_presentation_yields_675_unit_price(): void
    {
        // $135/kg × 5 kg de contenido = $675 unit_price.
        $item = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg');

        $this->assertSame(675.0, SaleItemMath::unitPriceForBasePrice($item, 135.0));
    }

    public function test_preferential_price_for_half_kilo_presentation_yields_67_50_unit_price(): void
    {
        // $135/kg × 0.5 kg = $67.50 unit_price. subtotal x2 = $135.
        $item = $this->presentationItem(quantity: 2, content: 0.500, unit: 'kg');

        $unitPrice = SaleItemMath::unitPriceForBasePrice($item, 135.0);

        $this->assertSame(67.5, $unitPrice);
        $this->assertSame(135.0, round($unitPrice * 2.0, 2));
    }

    public function test_preferential_price_for_loose_kilo_passes_through(): void
    {
        $item = ['quantity' => 2, 'quantity_unit' => 'kg', 'unit_type' => 'kg', 'presentation_snapshot' => null];

        $this->assertSame(135.0, SaleItemMath::unitPriceForBasePrice($item, 135.0));
    }

    // ─── isWeightOrVolume ───────────────────────────────────────────────────

    public function test_is_weight_or_volume_true_for_kg_presentation(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg');

        $this->assertTrue(SaleItemMath::isWeightOrVolume($item));
    }

    public function test_is_weight_or_volume_false_for_piece_presentation(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 1, unit: 'pieza');

        $this->assertFalse(SaleItemMath::isWeightOrVolume($item));
    }

    // ─── restoredUnitPrice ──────────────────────────────────────────────────

    public function test_restored_unit_price_uses_snapshot_price_when_present(): void
    {
        $item = $this->presentationItem(quantity: 1, content: 5.000, unit: 'kg', priceAtSnapshot: 750);

        $this->assertSame(750.0, SaleItemMath::restoredUnitPrice($item, 999.0));
    }

    public function test_restored_unit_price_falls_back_to_catalog_when_no_snapshot(): void
    {
        $item = ['quantity' => 1, 'quantity_unit' => 'kg', 'unit_type' => 'kg', 'presentation_snapshot' => null];

        $this->assertSame(150.0, SaleItemMath::restoredUnitPrice($item, 150.0));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function presentationItem(
        float $quantity,
        float $content,
        string $unit,
        float $priceAtSnapshot = 0.0,
        ?float $costAtSale = null,
    ): array {
        return [
            'quantity' => $quantity,
            'quantity_unit' => 'unit',
            'unit_type' => 'unit',
            'cost_price_at_sale' => $costAtSale,
            'presentation_snapshot' => [
                'id' => 1,
                'name' => 'preset',
                'content' => $content,
                'unit' => $unit,
                'price' => $priceAtSnapshot,
            ],
        ];
    }
}
