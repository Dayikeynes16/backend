<?php

namespace App\Services;

/**
 * Normalización canónica de teléfonos a E.164 mexicano.
 *
 * Es el único lugar del sistema que decide qué formato tiene un teléfono.
 * `Customer::phone` lo aplica por mutator, así que toda escritura de cliente
 * queda normalizada sin importar el canal (CRUD, hub, IA, pedido web, POS).
 */
class PhoneNormalizer
{
    /**
     * Devuelve el teléfono en E.164 (+52XXXXXXXXXX) o null si no hay dígitos
     * utilizables. Es idempotente: normalizar dos veces da lo mismo.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // +52 1 XXXXXXXXXX — formato móvil legacy, el 1 ya no se marca.
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            return '+52'.substr($digits, 3);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return '+52'.substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            return '+52'.$digits;
        }

        // Número extranjero o incompleto: se respeta tal cual, en E.164.
        return '+'.$digits;
    }

    /**
     * Formato legible de los 10 dígitos locales: `993 123 4567`.
     * Se usa para el nombre placeholder de clientes sin nombre.
     */
    public static function displayLocal(?string $e164): string
    {
        $digits = self::digits($e164 ?? '');

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) !== 10) {
            return $e164 ?? '';
        }

        return substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6);
    }

    public static function digits(string $e164): string
    {
        return preg_replace('/\D/', '', $e164) ?? '';
    }
}
