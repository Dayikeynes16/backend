<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Configuración de NEGOCIO de la sucursal desde el hub (admin-sucursal): métodos
 * de pago habilitados y gestión de API Keys (para vincular básculas por QR). La
 * config técnica del propio hub (backendUrl/puerto/token local) vive en el main
 * de Electron, no aquí.
 */
class ConfigController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);

        return response()->json([
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
            ],
            // Snapshot read-only "Datos administrados por la empresa" (paridad
            // con Sucursal\ConfiguracionController): ubicación y horario legible.
            'branch_snapshot' => [
                'name' => $branch->name,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
                'longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
                'schedule_text' => $this->humanReadableHours($branch->hours) ?: $branch->schedule,
            ],
            'payment_methods_enabled' => $branch->enabledPaymentMethods(),
            'supported_payment_methods' => Branch::SUPPORTED_PAYMENT_METHODS,
            'api_keys' => $this->apiKeyList($user->branch_id),
        ]);
    }

    /**
     * Solo los métodos de pago habilitados, para **ambos roles**.
     *
     * El hub guarda esto en su snapshot local para poder cobrar sin internet. La
     * config completa (API keys, datos de la empresa) sigue siendo de admin: aquí
     * se expone el mínimo, no se afloja `index()`.
     *
     * NO se añade a `BranchResource`: ese payload lo consumen las básculas que
     * hablan directo con la nube y su forma está congelada.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail($request->user()->branch_id);

        return response()->json([
            'payment_methods_enabled' => $branch->enabledPaymentMethods(),
        ]);
    }

    /** Horario humano-legible desde hours JSONB (misma lógica que la web). */
    private function humanReadableHours(?array $hours): ?string
    {
        if (empty($hours)) {
            return null;
        }

        $labels = ['mon' => 'Lun', 'tue' => 'Mar', 'wed' => 'Mié', 'thu' => 'Jue', 'fri' => 'Vie', 'sat' => 'Sáb', 'sun' => 'Dom'];
        $parts = [];
        foreach ($labels as $key => $label) {
            $day = $hours[$key] ?? null;
            if (! $day || empty($day['open']) || empty($day['close'])) {
                $parts[] = "{$label} cerrado";
            } else {
                $parts[] = "{$label} {$day['open']}-{$day['close']}";
            }
        }

        return implode(' · ', $parts);
    }

    public function updatePaymentMethods(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);

        $validated = $request->validate([
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['string', Rule::in(Branch::SUPPORTED_PAYMENT_METHODS)],
        ]);

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        // Normaliza al orden soportado y sin duplicados.
        $methods = array_values(array_intersect(Branch::SUPPORTED_PAYMENT_METHODS, $validated['payment_methods']));
        $branch->update(['payment_methods_enabled' => $methods]);

        return response()->json(['payment_methods_enabled' => $methods]);
    }

    /**
     * La llave de **un equipo**: la del propio hub, o la de una báscula que
     * acaba de emparejar.
     *
     * Existe aparte de [storeApiKey] por dos motivos, y los dos importan:
     *
     * 1. **La puede pedir un cajero.** Crear llaves sueltas sigue siendo de
     *    admin-sucursal, porque una API key vende sin sesión y no caduca. Pero
     *    atar la llave a un equipo concreto acota el riesgo: lo que sale de
     *    aquí sirve para esa báscula y se revoca sola cuando se revoca ella.
     *    Sin esto, un hub instalado en una sucursal donde sólo hay cajeros no
     *    conseguía llave nunca, se quedaba sin catálogo y las básculas que
     *    emparejaba no recibían productos.
     * 2. **Es idempotente por equipo.** `storeApiKey` crea una llave nueva en
     *    cada llamada; pedirla en cada arranque llenaría la sucursal de llaves
     *    huérfanas.
     *
     * Si el equipo ya tenía llave, **se revoca y se emite otra**. El `raw_key`
     * no se guarda —sólo su hash—, así que devolver la anterior es imposible; y
     * revocarla es justo lo que se quiere si la báscula se perdió y alguien la
     * está reinstalando.
     */
    public function deviceApiKey(Request $request, string $deviceId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return response()->json(['message' => 'Falta el identificador del equipo.'], 422);
        }

        $rawKey = 'csa_'.Str::random(40);
        $rotada = false;

        DB::transaction(function () use ($user, $deviceId, $validated, $rawKey, &$rotada) {
            // La anterior deja de servir en el mismo momento en que nace la
            // nueva: dos llaves vivas para un equipo es una que nadie recuerda
            // revocar.
            $rotada = ApiKey::withoutGlobalScopes()
                ->where('branch_id', $user->branch_id)
                ->where('device_id', $deviceId)
                ->delete() > 0;

            ApiKey::create([
                'tenant_id' => $user->tenant_id,
                'branch_id' => $user->branch_id,
                'device_id' => $deviceId,
                'name' => $validated['name'],
                'key_hash' => hash('sha256', $rawKey),
            ]);
        });

        // Como en `storeApiKey`: el `raw_key` viaja una vez y no se persiste.
        return response()->json([
            'raw_key' => $rawKey,
            'device_id' => $deviceId,
            'rotated' => $rotada,
        ], 201);
    }

    public function storeApiKey(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        $rawKey = 'csa_'.Str::random(40);
        ApiKey::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'name' => $validated['name'],
            'key_hash' => hash('sha256', $rawKey),
            'expires_at' => isset($validated['expires_in_days'])
                ? now()->addDays((int) $validated['expires_in_days'])
                : null,
        ]);

        // El rawKey solo se devuelve UNA vez (para el QR / copiar). No se persiste.
        return response()->json([
            'raw_key' => $rawKey,
            'api_keys' => $this->apiKeyList($user->branch_id),
        ], 201);
    }

    public function revokeApiKey(Request $request, int $apiKey): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $key = $this->findKey($user, $apiKey);

        $key->update(['status' => 'inactive']);

        return response()->json(['api_keys' => $this->apiKeyList($user->branch_id)]);
    }

    public function deleteApiKey(Request $request, int $apiKey): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $key = $this->findKey($user, $apiKey);

        if ($key->status === 'active' && ! $key->isExpired()) {
            return response()->json(['message' => 'Revoca la API Key antes de eliminarla.'], 422);
        }

        $key->delete();

        return response()->json(['api_keys' => $this->apiKeyList($user->branch_id)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function apiKeyList(int $branchId): array
    {
        return ApiKey::where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'prefix' => substr($key->key_hash, 0, 8),
                'status' => $key->isExpired() ? 'expired' : $key->status,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'expires_at' => $key->expires_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function findKey(User $user, int $apiKey): ApiKey
    {
        $key = ApiKey::where('branch_id', $user->branch_id)
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($apiKey);

        return $key;
    }

    private function ensureAdminSucursal(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede cambiar la configuración.'
        );

        // ApiKey usa TenantScope; enlazamos el tenant para que filtre por él.
        app()->instance('tenant', $user->tenant);
    }
}
