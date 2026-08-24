<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SaleCancellationRequested;
use App\Notifications\SaleCancellationResolved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El circuito de cancelaciones dejó de ser mudo.
 *
 * Antes el cajero pedía cancelar y nadie se enteraba: el administrador tenía
 * que entrar por su cuenta a la pantalla de cancelaciones, y el cajero se
 * quedaba sin saber el desenlace. Los avisos se guardan además de emitirse,
 * porque una decisión sobre dinero no puede depender de que alguien estuviera
 * mirando la pantalla en ese segundo.
 */
class CancelacionAvisosTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function makeSale(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 4300,
            'amount_paid' => 0,
            'amount_pending' => 4300,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ], $attrs));
    }

    public function test_a_cashier_request_notifies_the_branch_admin(): void
    {
        Notification::fake();
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->post(route('caja.request-cancel', [$this->tenant->slug, $sale->id]), [
                'cancel_request_reason' => 'El cliente se arrepintió',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->adminSucursal,
            SaleCancellationRequested::class,
            fn ($n) => $n->sale->id === $sale->id && $n->reason === 'El cliente se arrepintió',
        );
    }

    public function test_the_request_is_persisted_not_only_broadcast(): void
    {
        // Lo importante: si el administrador no estaba conectado, tiene que
        // encontrar el aviso cuando entre.
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->post(route('caja.request-cancel', [$this->tenant->slug, $sale->id]), [
                'cancel_request_reason' => 'Se pesó de más',
            ]);

        $this->assertSame(1, $this->adminSucursal->notifications()->count());

        $data = $this->adminSucursal->notifications()->first()->data;
        $this->assertSame('sale.cancellation.requested', $data['type']);
        $this->assertSame('action', $data['level']);
        $this->assertSame($sale->id, $data['sale_id']);
    }

    public function test_the_notification_uses_both_database_and_broadcast(): void
    {
        $sale = $this->makeSale();
        $notification = new SaleCancellationRequested($sale, 'Cajero', 'motivo');

        $this->assertSame(['database', 'broadcast'], $notification->via($this->adminSucursal));
    }

    public function test_approving_notifies_the_cashier_who_asked(): void
    {
        $sale = $this->makeSale([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $this->cajero->id,
            'cancel_request_reason' => 'Se pesó de más',
        ]);

        Notification::fake();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.cancelaciones.approve', [$this->tenant->slug, $sale->id]), [
                'cancel_reason' => 'Confirmado con el cliente',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->cajero,
            SaleCancellationResolved::class,
            fn ($n) => $n->outcome === 'approved' && $n->sale->id === $sale->id,
        );
    }

    public function test_rejecting_notifies_the_cashier_who_asked(): void
    {
        $sale = $this->makeSale([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $this->cajero->id,
            'cancel_request_reason' => 'Me equivoqué',
        ]);

        Notification::fake();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.cancelaciones.reject', [$this->tenant->slug, $sale->id]))
            ->assertRedirect();

        Notification::assertSentTo(
            $this->cajero,
            SaleCancellationResolved::class,
            fn ($n) => $n->outcome === 'rejected',
        );
    }

    public function test_an_admin_cancelling_on_his_own_notifies_nobody(): void
    {
        // Sin solicitud previa no hay a quién avisar del desenlace.
        $sale = $this->makeSale();
        Notification::fake();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.cancel', [$this->tenant->slug, $sale->id]), [
                'cancel_reason' => 'Error de captura',
            ]);

        Notification::assertNothingSentTo($this->cajero);
    }

    public function test_admins_of_another_branch_are_not_notified(): void
    {
        // Aislamiento: el aviso no puede cruzar de sucursal.
        $otherAdmin = $this->makeUser('otro@test.test', 'admin-sucursal', $this->secondBranch->id);

        Notification::fake();
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->post(route('caja.request-cancel', [$this->tenant->slug, $sale->id]), [
                'cancel_request_reason' => 'motivo',
            ]);

        Notification::assertSentTo($this->adminSucursal, SaleCancellationRequested::class);
        Notification::assertNothingSentTo($otherAdmin);
    }

    public function test_admins_of_another_tenant_are_not_notified(): void
    {
        // El branch_id es único global, pero el filtro por tenant es explícito
        // para que un cambio futuro no abra una fuga silenciosa entre empresas.
        $otherTenant = Tenant::create([
            'name' => 'Otra empresa',
            'slug' => 'otra-empresa',
            'max_branches' => 1,
            'max_users' => 5,
        ]);

        // Mismo branch_id que la venta, pero de otra empresa: es el escenario
        // que el filtro por tenant existe para cerrar.
        $foreign = User::create([
            'name' => 'Admin ajeno',
            'email' => 'ajeno@otra.test',
            'password' => bcrypt('secret'),
            'tenant_id' => $otherTenant->id,
            'branch_id' => $this->branch->id,
        ]);
        $foreign->assignRole('admin-sucursal');

        Notification::fake();
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->post(route('caja.request-cancel', [$this->tenant->slug, $sale->id]), [
                'cancel_request_reason' => 'motivo',
            ]);

        Notification::assertNothingSentTo($foreign);
    }

    public function test_a_failing_notification_does_not_break_the_request(): void
    {
        // El aviso es best-effort: la solicitud ya está guardada cuando se envía.
        $sale = $this->makeSale();

        Notification::shouldReceive('send')->andThrow(new \RuntimeException('mail down'));

        $this->actingAs($this->cajero)
            ->post(route('caja.request-cancel', [$this->tenant->slug, $sale->id]), [
                'cancel_request_reason' => 'motivo',
            ])
            ->assertRedirect();

        $this->assertNotNull($sale->fresh()->cancel_requested_at);
    }
}
