<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Notifications\SaleCancellationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La bandeja sólo devuelve lo propio. El aislamiento no lo da el tenant en la
 * ruta —estas rutas viven fuera del prefijo— sino `notifiable_id`: la relación
 * `notifications()` de un usuario no puede alcanzar las de otro.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function notify($user, string $folio = 'F-1'): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => $folio,
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ]);

        $user->notify(new SaleCancellationRequested($sale, 'Cajero', 'motivo'));
    }

    public function test_it_lists_only_the_own_notifications(): void
    {
        $this->notify($this->adminSucursal, 'F-MIA');
        $this->notify($this->cajero, 'F-AJENA');

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('notifications.index'))
            ->assertOk();

        $this->assertCount(1, $response->json('notifications'));
        $this->assertSame('F-MIA', $response->json('notifications.0.folio'));
        $this->assertSame(1, $response->json('unread_count'));
    }

    public function test_marking_as_read_lowers_the_counter(): void
    {
        $this->notify($this->adminSucursal);
        $id = $this->adminSucursal->notifications()->first()->id;

        $this->actingAs($this->adminSucursal)
            ->patchJson(route('notifications.read', $id))
            ->assertOk()
            ->assertJson(['unread_count' => 0]);

        $this->assertNotNull($this->adminSucursal->notifications()->first()->read_at);
    }

    public function test_it_cannot_mark_someone_elses_notification(): void
    {
        $this->notify($this->cajero);
        $foreignId = $this->cajero->notifications()->first()->id;

        $this->actingAs($this->adminSucursal)
            ->patchJson(route('notifications.read', $foreignId))
            ->assertNotFound();

        $this->assertNull($this->cajero->notifications()->first()->read_at);
    }

    public function test_mark_all_as_read(): void
    {
        $this->notify($this->adminSucursal, 'F-1');
        $this->notify($this->adminSucursal, 'F-2');

        $this->actingAs($this->adminSucursal)
            ->patchJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJson(['unread_count' => 0]);

        $this->assertSame(0, $this->adminSucursal->unreadNotifications()->count());
    }

    public function test_a_guest_gets_no_inbox(): void
    {
        $this->getJson(route('notifications.index'))->assertUnauthorized();
    }
}
