<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'payment_methods_enabled' => $branch->enabledPaymentMethods(),
            'supported_payment_methods' => Branch::SUPPORTED_PAYMENT_METHODS,
            'api_keys' => $this->apiKeyList($user->branch_id),
        ]);
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
