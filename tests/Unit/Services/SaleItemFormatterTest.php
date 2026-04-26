<?php

namespace Tests\Unit\Services;

use App\Services\SaleItemFormatter;
use Tests\TestCase;

class SaleItemFormatterTest extends TestCase
{
    private SaleItemFormatter $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SaleItemFormatter;
    }

    // ─── displayName ──────────────────────────────────────────────────────

    public function test_display_name_returns_product_name_when_no_snapshot(): void
    {
        $item = ['product_name' => 'Bistec', 'unit_type' => 'kg'];

        $this->assertSame('Bistec', $this->svc->displayName($item));
    }

    public function test_display_name_appends_content_and_unit_when_snapshot_present(): void
    {
        $item = [
            'product_name' => 'Queso - medio queso',
            'presentation_snapshot' => ['content' => 500, 'unit' => 'g', 'name' => 'medio queso'],
        ];

        $this->assertSame('Queso - medio queso (500 g)', $this->svc->displayName($item));
    }

    public function test_display_name_handles_kg_in_snapshot(): void
    {
        $item = [
            'product_name' => 'Aceite - 1 litro',
            'presentation_snapshot' => ['content' => 1, 'unit' => 'l'],
        ];

        $this->assertSame('Aceite - 1 litro (1.000 l)', $this->svc->displayName($item));
    }

    // ─── displayQuantity ──────────────────────────────────────────────────

    public function test_display_quantity_kg_uses_three_decimals(): void
    {
        $item = ['quantity' => 1.25, 'unit_type' => 'kg', 'quantity_unit' => 'kg'];

        $this->assertSame('1.250 kg', $this->svc->displayQuantity($item));
    }

    public function test_display_quantity_unit_uses_multiplier_format(): void
    {
        $item = ['quantity' => 2, 'quantity_unit' => 'unit', 'unit_type' => 'unit'];

        $this->assertSame('× 2', $this->svc->displayQuantity($item));
    }

    public function test_display_quantity_falls_back_to_unit_type_when_quantity_unit_absent(): void
    {
        // Legacy row: only unit_type set, no quantity_unit
        $item = ['quantity' => 2.5, 'unit_type' => 'kg'];

        $this->assertSame('2.500 kg', $this->svc->displayQuantity($item));
    }

    public function test_display_quantity_piece_uses_pz_label(): void
    {
        $item = ['quantity' => 3, 'unit_type' => 'piece', 'quantity_unit' => 'piece'];

        $this->assertSame('3 pz', $this->svc->displayQuantity($item));
    }

    public function test_display_quantity_strips_trailing_zeros_for_integers_in_unit_mode(): void
    {
        $item = ['quantity' => 1, 'quantity_unit' => 'unit'];

        $this->assertSame('× 1', $this->svc->displayQuantity($item));
    }

    // ─── realContent ──────────────────────────────────────────────────────

    public function test_real_content_for_weight_line(): void
    {
        $item = ['quantity' => 1.250, 'unit_type' => 'kg', 'quantity_unit' => 'kg'];

        $this->assertEquals(['amount' => 1.250, 'unit' => 'kg'], $this->svc->realContent($item));
    }

    public function test_real_content_multiplies_n_presentations_by_content(): void
    {
        // 2 medios quesos de 500 g cada uno = 1000 g → normalizado a 1.0 kg
        $item = [
            'quantity' => 2,
            'quantity_unit' => 'unit',
            'presentation_snapshot' => ['content' => 500, 'unit' => 'g'],
        ];

        $this->assertEquals(['amount' => 1.0, 'unit' => 'kg'], $this->svc->realContent($item));
    }

    public function test_real_content_keeps_grams_when_below_one_kilo(): void
    {
        $item = [
            'quantity' => 1,
            'quantity_unit' => 'unit',
            'presentation_snapshot' => ['content' => 500, 'unit' => 'g'],
        ];

        $this->assertEquals(['amount' => 500.0, 'unit' => 'g'], $this->svc->realContent($item));
    }

    public function test_real_content_for_unit_without_snapshot_keeps_unit(): void
    {
        $item = ['quantity' => 3, 'quantity_unit' => 'unit'];

        $this->assertEquals(['amount' => 3.0, 'unit' => 'unit'], $this->svc->realContent($item));
    }

    public function test_real_content_normalizes_ml_to_l(): void
    {
        $item = [
            'quantity' => 4,
            'quantity_unit' => 'unit',
            'presentation_snapshot' => ['content' => 500, 'unit' => 'ml'],
        ];

        $this->assertEquals(['amount' => 2.0, 'unit' => 'l'], $this->svc->realContent($item));
    }

    // ─── saleMode ─────────────────────────────────────────────────────────

    public function test_sale_mode_explicit_field_wins(): void
    {
        $item = ['sale_mode_at_sale' => 'presentation', 'unit_type' => 'kg'];

        $this->assertSame('presentation', $this->svc->saleMode($item));
    }

    public function test_sale_mode_inferred_from_snapshot_presence(): void
    {
        $item = ['presentation_snapshot' => ['content' => 1, 'unit' => 'kg']];

        $this->assertSame('presentation', $this->svc->saleMode($item));
    }

    public function test_sale_mode_inferred_from_legacy_unit_type_kg(): void
    {
        $item = ['unit_type' => 'kg'];

        $this->assertSame('weight', $this->svc->saleMode($item));
    }

    public function test_sale_mode_inferred_from_legacy_unit_type_piece(): void
    {
        $item = ['unit_type' => 'piece'];

        $this->assertSame('piece', $this->svc->saleMode($item));
    }

    public function test_sale_mode_unknown_when_nothing_set(): void
    {
        $this->assertSame('unknown', $this->svc->saleMode([]));
    }

    // ─── snapshot can come as string (jsonb might be returned raw) ────────

    public function test_display_name_decodes_string_snapshot(): void
    {
        $item = [
            'product_name' => 'Crema',
            'presentation_snapshot' => json_encode(['content' => 500, 'unit' => 'ml']),
        ];

        $this->assertSame('Crema (500 ml)', $this->svc->displayName($item));
    }
}
