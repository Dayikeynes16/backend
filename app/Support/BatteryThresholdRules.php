<?php

namespace App\Support;

/**
 * Las reglas de los dos umbrales de batería, en un solo sitio.
 *
 * Los edita el admin de sucursal desde su configuración y el admin de empresa
 * desde la pantalla de la sucursal. Si cada formulario llevara su copia, el día
 * que alguien cambie el tope lo cambiará en uno de los dos.
 *
 * Los rangos no son simétricos a propósito: con un aviso puesto en 5 no
 * existiría ningún crítico válido, y el control ofrecería un valor que ningún
 * guardado puede aceptar.
 */
final class BatteryThresholdRules
{
    /**
     * @return array<string, array<int, string>>
     *
     * En modo `$optional` (pantalla de empresa, que comparte formulario con
     * el resto de ajustes de la sucursal) los dos campos son un par
     * atómico: en vez de `sometimes` -que Laravel salta por completo cuando
     * el campo está ausente, incluidas sus reglas implícitas- usamos
     * `required_with` cruzado. Así, si llega uno de los dos, el otro pasa a
     * ser obligatorio y la comparación `gt` siempre corre con los dos
     * valores completos. Si no llega ninguno, ninguna regla se dispara y el
     * resto del formulario se guarda igual.
     */
    public static function rules(bool $optional = false): array
    {
        $warnPresence = $optional ? 'required_with:battery_critical_threshold' : 'required';
        $criticalPresence = $optional ? 'required_with:battery_warn_threshold' : 'required';

        return [
            'battery_warn_threshold' => [$warnPresence, 'integer', 'multiple_of:5', 'between:10,95', 'gt:battery_critical_threshold'],
            'battery_critical_threshold' => [$criticalPresence, 'integer', 'multiple_of:5', 'between:5,90'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'battery_warn_threshold.required' => 'El aviso de batería baja es obligatorio.',
            'battery_warn_threshold.required_with' => 'Si mandas el aviso urgente, también tienes que mandar el de batería baja.',
            'battery_warn_threshold.gt' => 'El aviso urgente tiene que ser menor que el de batería baja.',
            'battery_warn_threshold.multiple_of' => 'El porcentaje va de 5 en 5.',
            'battery_critical_threshold.required' => 'El aviso urgente es obligatorio.',
            'battery_critical_threshold.required_with' => 'Si mandas el aviso de batería baja, también tienes que mandar el urgente.',
            'battery_critical_threshold.multiple_of' => 'El porcentaje va de 5 en 5.',
            'battery_warn_threshold.between' => 'El aviso va entre 10 % y 95 %.',
            'battery_critical_threshold.between' => 'El aviso urgente va entre 5 % y 90 %.',
        ];
    }
}
