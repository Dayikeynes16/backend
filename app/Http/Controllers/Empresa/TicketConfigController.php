<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketConfigController extends Controller
{
    public function index(): Response
    {
        $tenant = app('tenant');

        $branches = Branch::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'phone', 'ticket_config']);

        return Inertia::render('Empresa/TicketConfig', [
            'branches' => $branches,
            'tenant' => $tenant,
        ]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $tenant = app('tenant');

        if ($branch->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'ticket_config' => 'required|array',
            'ticket_config.header_business_name' => 'boolean',
            'ticket_config.header_branch_name' => 'boolean',
            'ticket_config.header_address' => 'boolean',
            'ticket_config.header_phone' => 'boolean',
            'ticket_config.header_custom' => 'nullable|string|max:200',
            'ticket_config.show_date' => 'boolean',
            'ticket_config.show_folio' => 'boolean',
            'ticket_config.show_cashier' => 'boolean',
            'ticket_config.show_payment_method' => 'boolean',
            'ticket_config.footer_message' => 'nullable|string|max:200',
            'ticket_config.footer_custom' => 'nullable|string|max:300',
            'ticket_config.width' => 'in:58mm,80mm',
        ]);

        $branch->update(['ticket_config' => $validated['ticket_config']]);

        return back()->with('success', "Ticket de {$branch->name} actualizado.");
    }
}
