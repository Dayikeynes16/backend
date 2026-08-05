<?php

namespace Tests\Unit;

use App\Services\Purchases\PurchaseProductMergeService;
use PHPUnit\Framework\TestCase;

class PurchaseProductMergeNormalizationTest extends TestCase
{
    private PurchaseProductMergeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PurchaseProductMergeService(new \App\Services\AuditLogger());
    }

    public function test_moves_numeric_suffix_to_empty_notes(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res 111', null);
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('111', $r['notes']);
    }

    public function test_prepends_suffix_when_notes_already_has_content(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res 112', 'entregado frío');
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('112 · entregado frío', $r['notes']);
    }

    public function test_strips_non_space_separator(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res-123', null);
        $this->assertSame('123', $r['notes']);
    }

    public function test_exact_match_leaves_notes_untouched(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'canal de res', 'sin cambios');
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('sin cambios', $r['notes']);
    }

    public function test_non_prefix_preserves_whole_old_concept_in_notes(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'canal viejo raro', null);
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('canal viejo raro', $r['notes']);
    }

    public function test_false_prefix_is_not_treated_as_suffix(): void
    {
        // "Canal de resitas" empieza con "Canal de res" pero el siguiente char
        // es alfanumérico → es OTRO nombre, se preserva completo.
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de resitas', null);
        $this->assertSame('Canal de resitas', $r['notes']);
    }

    public function test_notes_truncated_to_500(): void
    {
        $long = str_repeat('x', 600);
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res 111', $long);
        $this->assertSame(500, mb_strlen($r['notes']));
        $this->assertStringStartsWith('111 · ', $r['notes']);
    }
}
