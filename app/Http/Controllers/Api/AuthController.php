<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const HUB_ROLES = ['cajero', 'admin-sucursal'];

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:120',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email o contraseña incorrectos.'], 401);
        }

        if (! $user->hasAnyRole(self::HUB_ROLES)) {
            return response()->json(['message' => 'Este usuario no puede usar el hub.'], 403);
        }

        if ($user->force_password_change) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña en la web antes de usar el hub.',
            ], 409);
        }

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Cambio de contraseña forzado desde el hub. El 409 del login ocurre ANTES
     * de emitir token, así que este endpoint autentica por credenciales (email
     * + contraseña temporal) igual que login, y en éxito espeja el flujo web
     * (Auth\ForcePasswordChangeController): Password::defaults() + confirmed,
     * limpia force_password_change y entrega sesión (token + user) directa.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:120',
            'new_password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email o contraseña incorrectos.'], 401);
        }

        if (! $user->hasAnyRole(self::HUB_ROLES)) {
            return response()->json(['message' => 'Este usuario no puede usar el hub.'], 403);
        }

        // Solo aplica al flujo forzado: sin el flag, la contraseña se cambia
        // desde el perfil web (paridad de alcance con ForcePasswordChange).
        if (! $user->force_password_change) {
            return response()->json(['message' => 'No necesitas cambiar tu contraseña.'], 409);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'force_password_change' => false,
        ]);

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * @return array{id:int,name:string,email:string,role:?string,branch_id:?int,branch_name:?string,cashier_expenses_enabled:bool,cashier_purchases_enabled:bool,tenant_id:?int,tenant_slug:?string}
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing(['branch', 'tenant']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
            // Feature flags por sucursal — el hub los usa para mostrar/ocultar
            // Gastos y Compras al cajero, con la misma regla que la web (CajeroLayout).
            'cashier_expenses_enabled' => (bool) $user->branch?->cashier_expenses_enabled,
            'cashier_purchases_enabled' => (bool) $user->branch?->cashier_purchases_enabled,
            // El hub muestra la pestaña Categorías de Gastos al admin-sucursal
            // solo si la empresa habilitó el toggle (misma regla que la web).
            'branch_admin_expense_categories_enabled' => (bool) $user->branch?->branch_admin_expense_categories_enabled,
            'tenant_id' => $user->tenant_id,
            'tenant_slug' => $user->tenant?->slug,
            // Branding para el shell del hub (logo + nombre de empresa, como
            // el sidebar web). logo_url es absoluta (Storage public / APP_URL).
            'tenant_name' => $user->tenant?->name,
            'tenant_logo_url' => $user->tenant?->logo_url,
        ];
    }
}
