<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
     * @return array{id:int,name:string,email:string,role:?string,branch_id:?int,branch_name:?string,tenant_id:?int,tenant_slug:?string}
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
            'tenant_id' => $user->tenant_id,
            'tenant_slug' => $user->tenant?->slug,
        ];
    }
}
