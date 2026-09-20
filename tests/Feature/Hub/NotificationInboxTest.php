<?php

namespace Tests\Feature\Hub;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Notifications\SaleCancellationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La misma bandeja de la campana web, servida bajo Sanctum para el hub.
 *
 * Lo que se prueba aquí no es el controlador —ya lo cubre
 * NotificationInboxTest— sino que el guard de token llega al mismo sitio y que
 * el aislamiento por `notifiable_id` se mantiene: un cajero con token no puede
 * ver ni marcar los avisos de su administrador.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function notify($user, string $folio): void
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

    public function test_the_tablet_reads_its_own_notifications_with_a_token(): void
    {
        $this->notify($this->adminSucursal, 'F-MIA');
        $this->notify($this->cajero, 'F-AJENA');

        Sanctum::actingAs($this->adminSucursal);

        $response = $this->getJson('/api/v1/hub/notifications')->assertOk();

        $this->assertCount(1, $response->json('notifications'));
        $this->assertSame('F-MIA', $response->json('notifications.0.folio'));
        $this->assertSame(1, $response->json('unread_count'));
    }

    public function test_marking_as_read_lowers_the_counter(): void
    {
        $this->notify($this->adminSucursal, 'F-1');
        Sanctum::actingAs($this->adminSucursal);

        $id = $this->getJson('/api/v1/hub/notifications')->json('notifications.0.id');

        $this->patchJson("/api/v1/hub/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_it_cannot_mark_a_notification_of_another_user(): void
    {
        // El 404 no es cortesía: la relación del usuario no alcanza el aviso
        // ajeno, así que no hay forma de leerlo ni de apagarlo.
        $this->notify($this->adminSucursal, 'F-AJENA');
        $id = $this->adminSucursal->notifications()->first()->id;

        Sanctum::actingAs($this->cajero);

        $this->patchJson("/api/v1/hub/notifications/{$id}/read")->assertNotFound();
        $this->assertNull($this->adminSucursal->notifications()->first()->read_at);
    }

    public function test_read_all_empties_the_badge(): void
    {
        $this->notify($this->adminSucursal, 'F-1');
        $this->notify($this->adminSucursal, 'F-2');

        Sanctum::actingAs($this->adminSucursal);

        $this->patchJson('/api/v1/hub/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $this->adminSucursal->unreadNotifications()->count());
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson('/api/v1/hub/notifications')->assertUnauthorized();
    }
}
