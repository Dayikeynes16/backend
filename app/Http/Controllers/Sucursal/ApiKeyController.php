<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(): Response
    {
        $branchId = Auth::user()->branch_id;

        $keys = ApiKey::where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'prefix' => substr($key->key_hash, 0, 8),
                'status' => $key->status,
                'last_used_at' => $key->last_used_at?->toDateTimeString(),
                'created_at' => $key->created_at->toDateTimeString(),
            ]);

        return Inertia::render('Sucursal/ApiKeys/Index', [
            'apiKeys' => $keys,
            'tenant' => app('tenant'),
            'newKey' => session('newKey'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = Auth::user();
        $rawKey = 'csa_' . Str::random(40);
        $hash = hash('sha256', $rawKey);

        ApiKey::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'name' => $request->name,
            'key_hash' => $hash,
        ]);

        return redirect()->route('sucursal.api-keys.index', app('tenant')->slug)
            ->with('newKey', $rawKey);
    }

    public function destroy(ApiKey $api_key): RedirectResponse
    {
        $api_key->update(['status' => 'inactive']);

        return redirect()->route('sucursal.api-keys.index', app('tenant')->slug)
            ->with('success', 'API Key revocada exitosamente.');
    }
}
