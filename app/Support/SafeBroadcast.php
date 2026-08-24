<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Emite un evento de broadcasting sin que un fallo del transporte pueda tumbar
 * la operación que ya se completó.
 *
 * Todos los eventos del sistema son `ShouldBroadcastNow`: la llamada HTTP a
 * Reverb ocurre dentro de la misma petición, de forma síncrona. Si Reverb está
 * caído o lento, la excepción sube por la pila y convierte en 500 una petición
 * cuyo trabajo real —la venta, el cobro— ya está confirmado en la base de datos.
 * Para quien llama (una báscula en el mostrador) eso es indistinguible de un
 * fallo, así que reintenta y duplica.
 *
 * El aviso en vivo es best-effort por definición: quien no lo recibe se pone al
 * día leyendo por HTTP. Perderlo nunca debe costar más que el propio aviso.
 */
final class SafeBroadcast
{
    /**
     * @param  callable():void  $dispatch  Normalmente `fn () => MiEvento::dispatch(...)`.
     * @param  array<string, mixed>  $context  Datos para localizar el evento perdido en el log.
     */
    public static function dispatch(callable $dispatch, string $event, array $context = []): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            Log::warning("{$event} broadcast failed", $context + ['error' => $e->getMessage()]);
        }
    }
}
