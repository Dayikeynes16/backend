<?php

namespace App\Services\Ai\Assistant;

use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Base que centraliza patrones comunes a todas las tools: resolución de
 * sucursal por nombre (case-insensitive), reescritura forzada de `branch_id`
 * para admin-sucursal, parseo de rangos de fecha relativos.
 *
 * NUNCA confiar en el `branch_id` o `branch_name` que devuelva el modelo si
 * el usuario es admin-sucursal: se reemplaza por `$user->branch_id`.
 */
abstract class AbstractAssistantTool implements AssistantTool
{
    public function readOnly(): bool
    {
        return true;
    }

    public function authorize(User $user, array $params): bool
    {
        foreach ($this->rolesAllowed() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resuelve un nombre de sucursal opcional a un Branch del tenant actual.
     * Si el usuario es admin-sucursal, se FUERZA a su sucursal sin importar
     * lo que diga el modelo. Si es admin-empresa y no especifica, devuelve
     * null (= todas las sucursales del tenant).
     */
    protected function resolveBranch(User $user, ?string $branchName): ?Branch
    {
        // Admin-sucursal y cajero: ignorar lo que diga el modelo, usar su sucursal.
        if ($user->hasRole('admin-sucursal') || $user->hasRole('cajero')) {
            return $user->branch_id ? Branch::find($user->branch_id) : null;
        }

        $name = trim((string) $branchName);
        if ($name === '') {
            return null;
        }

        return Branch::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Convierte un scope textual a [fecha_inicio, fecha_fin] en zona del app.
     * Si scope = 'custom', usa $dateFrom/$dateTo; si no, se ignoran.
     *
     * @return array{0: string, 1: string} YYYY-MM-DD inclusivo en ambos extremos
     */
    protected function resolveDateRange(string $scope, ?string $dateFrom, ?string $dateTo): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz);

        return match ($scope) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->subDay()->toDateString(), $today->subDay()->toDateString()],
            'this_week' => [$today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()],
            'last_week' => [$today->subWeek()->startOfWeek()->toDateString(), $today->subWeek()->endOfWeek()->toDateString()],
            'this_month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
            'last_month' => [$today->subMonth()->startOfMonth()->toDateString(), $today->subMonth()->endOfMonth()->toDateString()],
            'custom' => $this->sanitizeCustomRange($dateFrom, $dateTo, $today),
            default => [$today->toDateString(), $today->toDateString()],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sanitizeCustomRange(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $tz = config('app.timezone');
        $start = $from ? CarbonImmutable::parse($from, $tz) : $today;
        $end = $to ? CarbonImmutable::parse($to, $tz) : $start;

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Búsqueda difusa por nombre para entidades dichas por voz/texto libre:
     * insensible a acentos y mayúsculas, y tolerante a palabras extra — "rincón
     * del taco" encuentra al cliente "Rincon". Devuelve el match único (si la
     * puntuación del mejor es clara) o la lista de candidatos ordenada.
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): string  $nameOf
     * @return array{match: T|null, candidates: array<int, T>}
     */
    protected function fuzzyMatchByName(iterable $items, string $query, callable $nameOf): array
    {
        $q = $this->normalizeName($query);
        if ($q === '') {
            return ['match' => null, 'candidates' => []];
        }

        $stopwords = ['el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'al', 'y', 'lo'];
        $qTokens = array_values(array_filter(
            explode(' ', $q),
            fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, $stopwords, true),
        ));

        $scored = [];
        foreach ($items as $item) {
            $name = $this->normalizeName($nameOf($item));
            if ($name === '') {
                continue;
            }

            if ($name === $q) {
                $score = 100;
            } elseif (str_contains($name, $q)) {
                $score = 90; // lo dicho está contenido en el nombre
            } elseif (str_contains($q, $name)) {
                $score = 80; // el nombre está contenido en lo dicho ("rincon" ⊂ "rincon del taco")
            } else {
                $nameTokens = explode(' ', $name);
                $hits = 0;
                foreach ($qTokens as $t) {
                    foreach ($nameTokens as $nt) {
                        if ($nt !== '' && (str_contains($nt, $t) || str_contains($t, $nt))) {
                            $hits++;
                            break;
                        }
                    }
                }
                if ($hits === 0) {
                    continue;
                }
                $score = 40 + (10 * $hits);
            }

            $scored[] = ['item' => $item, 'score' => $score];
        }

        if ($scored === []) {
            return ['match' => null, 'candidates' => []];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $top = $scored[0];
        $runnerUp = $scored[1] ?? null;

        // Resolución única solo con señal clara: nunca adivinamos a quién
        // aplicar dinero — en empate o señal débil devolvemos candidatos.
        if ($runnerUp === null && $top['score'] >= 50) {
            return ['match' => $top['item'], 'candidates' => []];
        }
        if ($top['score'] >= 80 && ($runnerUp === null || $top['score'] > $runnerUp['score'])) {
            return ['match' => $top['item'], 'candidates' => []];
        }

        return [
            'match' => null,
            'candidates' => array_map(fn ($s) => $s['item'], array_slice($scored, 0, 8)),
        ];
    }

    /**
     * Minúsculas + sin acentos + espacios colapsados, para comparar nombres.
     */
    protected function normalizeName(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        $lower = strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);

        return preg_replace('/\s+/', ' ', $lower) ?? '';
    }
}
