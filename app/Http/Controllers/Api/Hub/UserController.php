<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Gestión de cajeros de la sucursal desde el hub (solo admin-sucursal, paridad
 * con Sucursal\UsuarioController). El admin solo administra usuarios con rol
 * cajero de SU sucursal; no gestiona su propia cuenta. El email es único global
 * y el alta respeta el límite max_users del tenant.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->ensureAdmin($admin);

        $request->validate([
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $search = trim((string) $request->input('search', ''));

        $cajeros = User::where('branch_id', $admin->branch_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'cajero'))
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15);

        $tenant = $admin->tenant;

        return response()->json([
            'data' => collect($cajeros->items())->map(fn (User $u) => $this->row($u))->values(),
            'meta' => [
                'current_page' => $cajeros->currentPage(),
                'last_page' => $cajeros->lastPage(),
                'total' => $cajeros->total(),
            ],
            // Cupo del tenant (todas las sucursales/roles), para el aviso de límite.
            'limits' => [
                'max_users' => (int) $tenant->max_users,
                'used' => $tenant->users()->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->ensureAdmin($admin);

        $tenant = $admin->tenant;
        if ($tenant->users()->count() >= $tenant->max_users) {
            return response()->json([
                'message' => "Has alcanzado el límite de {$tenant->max_users} usuarios permitidos.",
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::defaults()],
        ]);

        $cajero = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $admin->tenant_id,
            'branch_id' => $admin->branch_id,
        ]);
        $cajero->assignRole('cajero');

        return response()->json(['data' => $this->row($cajero)], 201);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $admin = $request->user();
        $this->ensureAdmin($admin);
        $cajero = $this->findCajero($admin, $user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($cajero->id)],
            'password' => ['nullable', Password::defaults()],
        ]);

        $cajero->update(array_merge([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ], filled($validated['password'] ?? null) ? ['password' => Hash::make($validated['password'])] : []));

        return response()->json(['data' => $this->row($cajero->refresh())]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $admin = $request->user();
        $this->ensureAdmin($admin);
        $cajero = $this->findCajero($admin, $user);

        $cajero->delete();

        return response()->json(['action' => 'deleted']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function ensureAdmin(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar cajeros.'
        );
    }

    /**
     * Cajero de la MISMA sucursal y tenant del admin. Cualquier otro usuario
     * (otra sucursal, otro tenant, o no-cajero como el propio admin) → 404/403,
     * misma regla que UserPolicy::update en la web.
     */
    private function findCajero(User $admin, int $userId): User
    {
        $target = User::where('branch_id', $admin->branch_id)
            ->where('tenant_id', $admin->tenant_id)
            ->findOrFail($userId);

        abort_unless($target->hasRole('cajero'), 403, 'Solo puedes gestionar cajeros de tu sucursal.');

        return $target;
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
