# Reverb y WebSockets

Laravel Reverb proporciona WebSockets nativos para comunicación en tiempo real entre la API y el cajero.

## Responsabilidades

- Notificar al cajero instantáneamente cuando llega una venta nueva desde la API.
- Mantener canales privados por sucursal para aislamiento.

**No hace:** no persiste mensajes. No maneja reconexiones (Echo lo hace automáticamente).

## Canal de broadcast

Canal privado por sucursal: `sucursal.{branchId}`

### Autorización (`routes/channels.php`)

```php
Broadcast::channel('sucursal.{branchId}', function ($user, $branchId) {
    return $user->branch_id === (int) $branchId;
});
```

Solo usuarios autenticados y asignados a esa sucursal pueden suscribirse.

## Evento `NewExternalSale` (`app/Events/NewExternalSale.php`)

Implementa `ShouldBroadcast`. Se dispara después de crear una venta vía API.

```php
class NewExternalSale implements ShouldBroadcast
{
    public function __construct(public Sale $sale) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("sucursal.{$this->sale->branch_id}");
    }

    public function broadcastWith(): array
    {
        $this->sale->load('items');
        return [
            'sale' => SaleResource::make($this->sale)->toArray(request()),
        ];
    }
}
```

## Suscripción en Vue (`resources/js/bootstrap.js`)

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

## Configuración

Variables en `.env`:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=carniceria
REVERB_APP_KEY=carniceria_key
REVERB_APP_SECRET=carniceria_secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Puerto `8080` expuesto en `compose.yaml` para el contenedor Laravel.

## Levantar Reverb en desarrollo

```bash
./vendor/bin/sail artisan reverb:start
```
