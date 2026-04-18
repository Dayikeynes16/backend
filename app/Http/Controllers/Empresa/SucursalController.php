<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function index(Request $request): Response
    {
        $sucursales = Branch::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->withCount('users')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Empresa/Sucursales/Index', [
            'sucursales' => $sucursales,
            'filters' => $request->only('search'),
            'tenant' => app('tenant'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Empresa/Sucursales/Create', [
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'phone' => 'nullable|string|max:20',
            'schedule' => 'nullable|string|max:255',
        ]);

        $tenant = app('tenant');

        if ($tenant->branches()->count() >= $tenant->max_branches) {
            return back()->with('error', "Has alcanzado el limite de {$tenant->max_branches} sucursales permitidas.");
        }

        $validated['tenant_id'] = $tenant->id;

        Branch::create($validated);

        return redirect()->route('empresa.sucursales.index', $tenant->slug)
            ->with('success', 'Sucursal creada exitosamente.');
    }

    public function edit(Branch $sucursal): Response
    {
        $this->authorizeBranchAccess($sucursal);

        $sucursal->load(['users' => fn ($q) => $q->with('roles')->orderBy('name')]);
        $sucursal->loadCount('users');

        return Inertia::render('Empresa/Sucursales/Edit', [
            'sucursal' => $sucursal,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, Branch $sucursal): RedirectResponse
    {
        $this->authorizeBranchAccess($sucursal);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'phone' => 'nullable|string|max:20',
            'schedule' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'online_ordering_enabled' => 'sometimes|boolean',
            'delivery_enabled' => 'sometimes|boolean',
            'pickup_enabled' => 'sometimes|boolean',
            'delivery_tiers' => 'nullable|array',
            'delivery_tiers.*.max_km' => 'required_with:delivery_tiers|numeric|gt:0',
            'delivery_tiers.*.fee' => 'required_with:delivery_tiers|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'public_phone' => 'nullable|string|max:20',
            'hours' => 'nullable|array',
            'hours.*.open' => 'nullable|date_format:H:i',
            'hours.*.close' => 'nullable|date_format:H:i',
        ]);

        $this->validateOnlineOrderingConfig($validated);

        if (! empty($validated['public_phone'])) {
            $validated['public_phone'] = PhoneNormalizer::normalize($validated['public_phone']);
        }

        if (array_key_exists('delivery_tiers', $validated) && is_array($validated['delivery_tiers'])) {
            usort($validated['delivery_tiers'], fn ($a, $b) => ($a['max_km'] ?? 0) <=> ($b['max_km'] ?? 0));
        }

        if (array_key_exists('hours', $validated)) {
            $validated['hours'] = $this->normalizeHours($validated['hours']);
        }

        $sucursal->update($validated);

        return redirect()->route('empresa.sucursales.index', app('tenant')->slug)
            ->with('success', 'Sucursal actualizada exitosamente.');
    }

    private function validateOnlineOrderingConfig(array $data): void
    {
        $errors = [];

        if (! empty($data['online_ordering_enabled'])) {
            if (empty($data['public_phone'])) {
                $errors['public_phone'] = 'Requerido cuando los pedidos en línea están activos.';
            }

            if (empty($data['delivery_enabled']) && empty($data['pickup_enabled'])) {
                $errors['delivery_enabled'] = 'Debes permitir al menos envío o recolección.';
            }
        }

        if (! empty($data['delivery_enabled'])) {
            if (empty($data['latitude']) || empty($data['longitude'])) {
                $errors['latitude'] = 'Requerido para calcular distancias de envío.';
            }

            if (empty($data['delivery_tiers']) || ! is_array($data['delivery_tiers']) || count($data['delivery_tiers']) === 0) {
                $errors['delivery_tiers'] = 'Debes configurar al menos un rango de envío.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function normalizeHours(?array $hours): ?array
    {
        if ($hours === null) {
            return null;
        }

        $normalized = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $entry = $hours[$day] ?? null;
            if (! $entry || empty($entry['open']) || empty($entry['close'])) {
                $normalized[$day] = null;
                continue;
            }
            $normalized[$day] = [
                'open' => $entry['open'],
                'close' => $entry['close'],
            ];
        }

        return $normalized;
    }

    public function destroy(Branch $sucursal): RedirectResponse
    {
        $this->authorizeBranchAccess($sucursal);

        $sucursal->delete();

        return redirect()->route('empresa.sucursales.index', app('tenant')->slug)
            ->with('success', 'Sucursal eliminada exitosamente.');
    }

    private function authorizeBranchAccess(Branch $sucursal): void
    {
        if ($sucursal->tenant_id !== app('tenant')->id) {
            abort(403, 'Esta sucursal no pertenece a tu empresa.');
        }
    }
}
