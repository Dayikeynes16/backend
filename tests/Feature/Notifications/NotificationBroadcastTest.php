<?php

namespace Tests\Feature\Notifications;

use App\Enums\SaleStatus;
use App\Models\Device;
use App\Models\Sale;
use App\Notifications\DeviceBatteryLow;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceRegistered;
use App\Notifications\DeviceSilent;
use App\Notifications\SaleCancellationRequested;
use App\Notifications\SaleCancellationResolved;
use App\Services\Devices\DeviceAlertService;
use App\Services\SaleCancellationNotifier;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Los avisos salen en vivo de verdad.
 *
 * `BroadcastNotificationCreated` es `ShouldBroadcast` (encolado) y producción
 * no corre colas: sin `onConnection('sync')` el aviso sólo se guardaba. Y sin
 * `broadcastType()` el `type` en vivo era el nombre de la clase, distinto del
 * guardado. Al volverse síncrono, un Reverb caído puede lanzar: la guardia va
 * por destinatario para que el primero que falle no deje a los demás sin fila.
 */
class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function makeSale(): Sale
    {
        return Sale::create([
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
        ]);
    }

    private function makeDevice(): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'device_id' => 'tab-'.uniqid(),
            'kind' => 'scale_android',
            'name' => 'Balanza 1',
            'app_version' => '1.0.0',
            'via' => 'cloud',
            'battery_level' => 10,
            'last_seen_at' => now(),
            'first_seen_at' => now(),
        ]);
    }

    /** @return array<string, array{\Closure(self): Notification}> */
    public static function notifications(): array
    {
        return [
            'cancelación pedida' => [fn (self $t) => new SaleCancellationRequested($t->makeSale(), 'Caja', 'motivo')],
            'cancelación aprobada' => [fn (self $t) => new SaleCancellationResolved($t->makeSale(), 'approved', 'Admin')],
            'cancelación rechazada' => [fn (self $t) => new SaleCancellationResolved($t->makeSale(), 'rejected', 'Admin', 'no')],
            'batería baja' => [fn (self $t) => new DeviceBatteryLow($t->makeDevice(), 'critical')],
            'equipo nuevo' => [fn (self $t) => new DeviceRegistered($t->makeDevice())],
            'equipo sin reportar' => [fn (self $t) => new DeviceSilent($t->makeDevice())],
            'versión atrasada' => [fn (self $t) => new DeviceOutdated($t->makeDevice(), '2.0.0')],
        ];
    }

    #[DataProvider('notifications')]
    public function test_live_type_matches_stored_type_and_goes_sync(\Closure $make): void
    {
        $notification = $make($this);
        $notifiable = $this->adminSucursal;

        $this->assertSame($notification->toArray($notifiable)['type'], $notification->broadcastType());
        $this->assertSame('sync', $notification->toBroadcast($notifiable)->connection);
    }

    /** Sustituye el transporte por uno que siempre falla, como un Reverb caído. */
    private function breakBroadcasting(): void
    {
        Broadcast::extend('boom', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Reverb caído.');
            }
        });

        config([
            'broadcasting.default' => 'boom',
            'broadcasting.connections.boom' => ['driver' => 'boom'],
        ]);
    }

    public function test_every_branch_admin_keeps_the_cancellation_row_when_broadcast_fails(): void
    {
        $secondAdmin = $this->makeUser('suc2@test.local', 'admin-sucursal', $this->branch->id);
        $sale = $this->makeSale();
        $this->breakBroadcasting();

        app(SaleCancellationNotifier::class)->requested($sale, $this->cajero, 'motivo');

        $this->assertSame(1, $this->adminSucursal->notifications()->where('type', SaleCancellationRequested::class)->count());
        $this->assertSame(1, $secondAdmin->notifications()->where('type', SaleCancellationRequested::class)->count());
    }

    public function test_every_device_alert_recipient_keeps_the_row_when_broadcast_fails(): void
    {
        $device = $this->makeDevice();
        $this->breakBroadcasting();

        app(DeviceAlertService::class)->notify($device, new DeviceSilent($device));

        $this->assertSame(1, $this->adminSucursal->notifications()->where('type', DeviceSilent::class)->count());
        $this->assertSame(1, $this->adminEmpresa->notifications()->where('type', DeviceSilent::class)->count());
    }
}
