<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\ProviderType;
use App\Http\Controllers\Concerns\HandlesProviderWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubProviderResource;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Catálogo de proveedores (tenant-wide) para el admin-sucursal desde el hub.
 * Lectura siempre; crear/editar solo si la empresa habilitó el toggle
 * `branch_admin_providers_enabled` de su sucursal (mismo modelo que la web).
 * El borrado queda en empresa. Reusa la validación de HandlesProviderWrites;
 * sobreescribe store/update para devolver JSON en lugar de redirects Inertia.
 */
class ProviderController extends Controller
{
    use HandlesProviderWrites;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureAdminSucursal($user);

        $request->validate([
            'q' => 'nullable|string|max:100',
            'type' => ['nullable', Rule::enum(ProviderType::class)],
        ]);

        $canManage = $this->canManage($user);
        $search = trim((string) $request->input('q', ''));
        $type = $request->input('type');

        $providers = Provider::query()
            // Cuando puede gestionar mostramos también inactivos (para editar/reactivar);
            // de lo contrario solo los activos.
            ->when(! $canManage, fn ($q) => $q->where('status', 'active'))
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => HubProviderResource::collection($providers),
            'can_manage' => $canManage,
            'types' => array_map(
                fn (ProviderType $t) => ['value' => $t->value, 'label' => $t->label()],
                ProviderType::cases()
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureCanManage($user);

        $validated = $this->validatedProviderRequest($request, $user->tenant_id);

        $provider = Provider::create(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
        ]));

        return response()->json(['data' => HubProviderResource::make($provider)], 201);
    }

    public function update(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureCanManage($user);

        // Scope de tenant explícito: cross-tenant → 404.
        $found = Provider::where('tenant_id', $user->tenant_id)->findOrFail($provider);

        $validated = $this->validatedProviderRequest($request, $user->tenant_id, $found->id, withStatus: true);

        $found->update($validated);

        return response()->json(['data' => HubProviderResource::make($found->refresh())]);
    }

    private function ensureAdminSucursal(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar proveedores.'
        );
    }

    /**
     * El admin-sucursal puede gestionar proveedores si la empresa habilitó el
     * toggle en su sucursal. superadmin siempre puede.
     */
    private function canManage(User $user): bool
    {
        if ($user->hasRole('superadmin')) {
            return true;
        }

        if (! $user->hasRole('admin-sucursal')) {
            return false;
        }

        $branch = $user->branch_id ? Branch::withoutGlobalScopes()->find($user->branch_id) : null;

        return (bool) ($branch?->branch_admin_providers_enabled);
    }

    private function ensureCanManage(User $user): void
    {
        abort_unless(
            $this->canManage($user),
            403,
            'Tu empresa no ha habilitado la gestión de proveedores para tu sucursal.'
        );
    }
}
