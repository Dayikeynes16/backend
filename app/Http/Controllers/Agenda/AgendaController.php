<?php

namespace App\Http\Controllers\Agenda;

use App\Enums\AgendaRecurrence;
use App\Events\AgendaItemAssigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\StoreAgendaItemRequest;
use App\Http\Requests\Agenda\UpdateAgendaItemRequest;
use App\Models\AgendaItem;
use App\Models\Branch;
use App\Models\User;
use App\Services\Agenda\AgendaAlertService;
use App\Services\Agenda\AgendaCalendarService;
use App\Services\Agenda\IcsBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AgendaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, AgendaAlertService $alerts): \Inertia\Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        $today = AgendaItem::visibleTo($user)
            ->whereNull('completed_at')
            ->where(function ($q) {
                $q->whereDate('starts_at', '<=', now())
                    ->orWhereDate('remind_at', '<=', now());
            })
            ->orderBy('starts_at')
            ->get();

        $upcoming = AgendaItem::visibleTo($user)
            ->whereNull('completed_at')
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [now()->addDay()->startOfDay(), now()->addWeek()->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Agenda/Index', [
            'today' => $today,
            'upcoming' => $upcoming,
            'alerts' => $alerts->for($user),
            'branches' => $this->branchesForUser($user),
            'assignableUsers' => $this->assignableUsers($user),
            'tenant' => $tenant,
        ]);
    }

    public function calendar(Request $request, AgendaCalendarService $calendar): JsonResponse
    {
        $user = Auth::user();
        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->query('to', now()->endOfMonth()->toDateString()));

        $occurrences = $calendar->expand(AgendaItem::visibleTo($user), $from, $to->endOfDay());

        return response()->json([
            'occurrences' => collect($occurrences)->map(fn ($o) => [
                'id' => $o['item']->id,
                'title' => $o['item']->title,
                'type' => $o['item']->type->value,
                'starts_at' => $o['starts_at']->toIso8601String(),
                'all_day' => $o['item']->all_day,
            ])->values(),
        ]);
    }

    public function alerts(AgendaAlertService $alerts): JsonResponse
    {
        return response()->json(['alerts' => $alerts->for(Auth::user())]);
    }

    public function store(StoreAgendaItemRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $request->validated();
        $data['user_id'] = $user->id;
        $data['tenant_id'] = app('tenant')->id;

        // branch no admin solo puede ser su sucursal
        if (($data['scope'] ?? null) === 'branch' && ! ($user->hasRole('admin-empresa') || $user->hasRole('superadmin'))) {
            $data['branch_id'] = $user->branch_id;
        }

        $item = AgendaItem::create($data);

        if ($item->assigned_to_user_id && $item->assigned_to_user_id !== $user->id) {
            AgendaItemAssigned::dispatch($item, $item->assigned_to_user_id);
        }

        return back()->with('success', 'Agregado a la agenda.');
    }

    public function update(UpdateAgendaItemRequest $request, AgendaItem $item): RedirectResponse
    {
        $item->update($request->validated());

        return back()->with('success', 'Actualizado.');
    }

    public function complete(AgendaItem $item): RedirectResponse
    {
        $this->authorize('complete', $item);

        $item->update(['completed_at' => now()]);

        // Recurrencia: genera la siguiente ocurrencia viva.
        $recurrence = $item->recurrence ?? AgendaRecurrence::None;
        if ($recurrence !== AgendaRecurrence::None && $item->starts_at) {
            $next = $recurrence->advance($item->starts_at);
            $until = $item->recurrence_until?->copy()->endOfDay();
            if (! $until || $next->lte($until)) {
                $clone = $item->replicate(['completed_at']);
                $clone->completed_at = null;
                $clone->starts_at = $next;
                if ($item->remind_at && $item->starts_at) {
                    $offset = $item->remind_at->diffInSeconds($item->starts_at, false);
                    $clone->remind_at = $next->copy()->addSeconds($offset);
                }
                $clone->save();
            }
        }

        return back()->with('success', 'Marcado como hecho.');
    }

    public function destroy(AgendaItem $item): RedirectResponse
    {
        $this->authorize('delete', $item);
        $item->delete();

        return back()->with('success', 'Eliminado.');
    }

    public function ics(AgendaItem $item, IcsBuilder $builder): Response
    {
        $this->authorize('view', $item);
        $ics = $builder->forItem($item, app('tenant')->slug);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="agenda-'.$item->id.'.ics"',
        ]);
    }

    /** @return array<int, array{id:int,name:string}> */
    private function branchesForUser($user): array
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $query = Branch::query()->where('status', 'active');
        if (! $isCompanyAdmin) {
            $query->where('id', $user->branch_id);
        }

        return $query->orderBy('name')->get(['id', 'name'])->toArray();
    }

    /** @return array<int, array{id:int,name:string}> */
    private function assignableUsers($user): array
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $query = User::query()->where('tenant_id', $user->tenant_id);
        if (! $isCompanyAdmin) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query->orderBy('name')->get(['id', 'name'])->toArray();
    }
}
