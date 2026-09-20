<?php

namespace App\Services\Devices;

use App\Models\Branch;

/**
 * Los dos porcentajes a los que una sucursal quiere enterarse, y la única
 * comparación entre un nivel de batería y ellos.
 *
 * Existe porque el mismo criterio lo necesitan tres sitios —a quién se avisa,
 * qué estado se pinta y de qué color va la barra— y tres copias del mismo `if`
 * se separan el día que alguien cambia una.
 *
 * Sin sucursal (o sin valores guardados) caen a 20/10, que es lo que estaba
 * escrito a mano antes de que esto existiera.
 */
final class BatteryThresholds
{
    public function __construct(
        public readonly int $warn = 20,
        public readonly int $critical = 10,
    ) {}

    public static function fromBranch(?Branch $branch): self
    {
        return new self(
            (int) ($branch?->battery_warn_threshold ?? 20),
            (int) ($branch?->battery_critical_threshold ?? 10),
        );
    }

    /**
     * `critical`, `warn` o null si no hay nada que avisar.
     *
     * Cargando no es una alerta aunque el número sea bajo: alguien ya se ocupó.
     * Sin lectura tampoco: un equipo de escritorio enchufado a la pared no debe
     * molestar nunca.
     */
    public function severityFor(?int $level, bool $charging): ?string
    {
        if ($level === null || $charging) {
            return null;
        }

        if ($level <= $this->critical) {
            return 'critical';
        }

        return $level <= $this->warn ? 'warn' : null;
    }
}
