<?php

namespace Tests\Unit\Enums;

use App\Enums\SaleStatus;
use Tests\TestCase;

class SaleStatusTest extends TestCase
{
    public function test_returns_spanish_label_for_each_status(): void
    {
        $this->assertSame('Activa', SaleStatus::Active->label());
        $this->assertSame('Pendiente', SaleStatus::Pending->label());
        $this->assertSame('Cobrada', SaleStatus::Completed->label());
        $this->assertSame('Cancelada', SaleStatus::Cancelled->label());
        $this->assertSame('Cumplida', SaleStatus::Fulfilled->label());
    }

    public function test_returns_color_for_each_status(): void
    {
        $this->assertSame('blue', SaleStatus::Active->color());
        $this->assertSame('amber', SaleStatus::Pending->color());
        $this->assertSame('green', SaleStatus::Completed->color());
        $this->assertSame('red', SaleStatus::Cancelled->color());
        $this->assertSame('emerald', SaleStatus::Fulfilled->color());
    }

    public function test_pending_can_transition_to_active_cancelled_and_fulfilled(): void
    {
        $allowed = SaleStatus::Pending->allowedTransitions();

        $this->assertContains(SaleStatus::Active, $allowed);
        $this->assertContains(SaleStatus::Cancelled, $allowed);
        $this->assertContains(SaleStatus::Fulfilled, $allowed);
        $this->assertNotContains(SaleStatus::Completed, $allowed);
    }

    public function test_fulfilled_can_only_transition_back_to_pending(): void
    {
        $allowed = SaleStatus::Fulfilled->allowedTransitions();

        $this->assertSame([SaleStatus::Pending], $allowed);
    }

    public function test_can_transition_helper_reflects_allowed_transitions(): void
    {
        $this->assertTrue(SaleStatus::Pending->canTransitionTo(SaleStatus::Fulfilled));
        $this->assertTrue(SaleStatus::Fulfilled->canTransitionTo(SaleStatus::Pending));
        $this->assertFalse(SaleStatus::Fulfilled->canTransitionTo(SaleStatus::Active));
        $this->assertFalse(SaleStatus::Fulfilled->canTransitionTo(SaleStatus::Completed));
        $this->assertFalse(SaleStatus::Fulfilled->canTransitionTo(SaleStatus::Cancelled));
    }

    public function test_cancelled_remains_terminal_after_fulfilled_was_added(): void
    {
        $this->assertSame([], SaleStatus::Cancelled->allowedTransitions());
    }
}
