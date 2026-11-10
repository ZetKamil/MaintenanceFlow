<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Models\Installation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstallationController extends Controller
{
    /**
     * Paginated, filterable installation registry (Owner only).
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'Only the owner can browse the full installation registry.');

        $today = Carbon::today();
        $soonLimit = $today->copy()->addDays(30);

        $query = Installation::with(['customer', 'maintenancePlans'])
            ->withCount('components');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%")
                    ->orWhere('location_city', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if ($type = strtoupper(trim((string) $request->query('type', '')))) {
            $query->where('installation_type', $type);
        }

        if ($due = trim((string) $request->query('due', ''))) {
            $query->whereHas('maintenancePlans', function ($q) use ($due, $today, $soonLimit) {
                match ($due) {
                    'overdue' => $q->whereNotNull('next_due_at')->whereDate('next_due_at', '<', $today),
                    'due_soon' => $q->whereNotNull('next_due_at')->whereDate('next_due_at', '>=', $today)->whereDate('next_due_at', '<=', $soonLimit),
                    'needs_review' => $q->where(fn($sub) => $sub->whereNull('next_due_at')->orWhere('interval_months', 0)),
                    default => null,
                };
            });
        }

        $installations = $query->orderByRaw("CASE WHEN installation_type = 'BRAND' THEN 0 ELSE 1 END")
            ->orderBy('external_id')
            ->paginate(20);

        $installations->getCollection()->transform(function (Installation $inst) use ($today) {
            $plan = $inst->maintenancePlans->first();

            return [
                'id' => $inst->id,
                'external_id' => $inst->external_id,
                'name' => $inst->name,
                'installation_type' => $inst->installation_type,
                'is_fire_safety' => $inst->isFireSafety(),
                'is_active' => $inst->is_active,
                'customer_name' => $inst->customer->name,
                'customer_phone' => $inst->customer->phone,
                'location_city' => $inst->location_city,
                'components_count' => $inst->components_count,
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'interval_months' => $plan->interval_months,
                    'last_completed_at' => $plan->last_completed_at?->toDateString(),
                    'next_due_at' => $plan->next_due_at?->toDateString(),
                    'is_overdue' => $plan->next_due_at !== null && $plan->next_due_at->lt($today),
                ] : null,
            ];
        });

        return response()->json($installations);
    }

    /**
     * Full installation file: customer, maintenance plans, hardware components, and service history.
     */
    public function show(Request $request, Installation $installation): JsonResponse
    {
        $user = $request->user();

        if ($user->isTechnician()) {
            $hasAssignedOrder = $installation->workOrders()
                ->where('assigned_technician_id', $user->id)
                ->exists();

            abort_unless($hasAssignedOrder, 403, 'You can only view installations assigned to your work orders.');
        }

        $installation->load([
            'customer',
            'maintenancePlans',
            'components' => fn($q) => $q->orderBy('id'),
            'workOrders' => fn($q) => $q->with(['technician:id,name', 'report.submittedBy:id,name', 'materials'])
                ->orderByDesc('scheduled_start_at')
                ->orderByDesc('cycle_due_date')
                ->orderByDesc('id'),
        ]);

        return response()->json([
            'installation' => [
                'id' => $installation->id,
                'external_id' => $installation->external_id,
                'name' => $installation->name,
                'installation_type' => $installation->installation_type,
                'is_fire_safety' => $installation->isFireSafety(),
                'is_active' => $installation->is_active,
                'location_street' => $installation->location_street,
                'location_postal_code' => $installation->location_postal_code,
                'location_city' => $installation->location_city,
                'customer' => $installation->customer,
                'maintenance_plans' => $installation->maintenancePlans->map(fn($p) => [
                    'id' => $p->id,
                    'maintenance_type' => $p->maintenance_type,
                    'interval_months' => $p->interval_months,
                    'last_completed_at' => $p->last_completed_at?->toDateString(),
                    'next_due_at' => $p->next_due_at?->toDateString(),
                    'source_notes' => $p->source_notes,
                    'is_overdue' => $p->isOverdue(),
                ]),
                'components' => $installation->components,
                'work_orders' => $installation->workOrders->map(fn($wo) => [
                    'id' => $wo->id,
                    'external_id' => $wo->external_id,
                    'status' => $wo->status->value,
                    'status_label' => $wo->status->label(),
                    'cycle_due_date' => $wo->cycle_due_date?->toDateString(),
                    'scheduled_start_at' => $wo->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $wo->scheduled_end_at?->toIso8601String(),
                    'technician' => $wo->technician,
                    'notes' => $wo->notes,
                    'report' => $wo->report,
                    'materials' => $wo->materials,
                    'is_terminal' => $wo->status === WorkOrderStatus::COMPLETED || $wo->status === WorkOrderStatus::CANCELLED,
                ]),
            ],
        ]);
    }
}