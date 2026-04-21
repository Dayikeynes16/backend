<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentMethod;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    public function test_returns_spanish_label_for_each_known_method(): void
    {
        $this->assertSame('Efectivo', PaymentMethod::Cash->label());
        $this->assertSame('Tarjeta', PaymentMethod::Card->label());
        $this->assertSame('Transferencia', PaymentMethod::Transfer->label());
        $this->assertSame('Crédito', PaymentMethod::Credit->label());
    }

    public function test_resolves_known_slug_through_resolveLabel(): void
    {
        $this->assertSame('Efectivo', PaymentMethod::resolveLabel('cash'));
        $this->assertSame('Tarjeta', PaymentMethod::resolveLabel('card'));
    }

    public function test_resolves_unknown_slug_to_title_case_with_spaces(): void
    {
        $this->assertSame('Vale Despensa', PaymentMethod::resolveLabel('vale_despensa'));
        $this->assertSame('Monedero Electronico', PaymentMethod::resolveLabel('monedero_electronico'));
    }

    public function test_resolves_single_word_unknown_slug(): void
    {
        $this->assertSame('Crypto', PaymentMethod::resolveLabel('crypto'));
    }
}
