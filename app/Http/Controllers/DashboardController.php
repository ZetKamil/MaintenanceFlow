<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Return role-aware dashboard metrics and priority queue.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isTechnician()) {
            return $this->technicianDashboard($user);
        }

        return $this->ownerDashboard();
    }

    /**
     * Return active technicians for Dirk's dispatch dropdowns and calendar columns.
     */
    public function technicians(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'Only the owner can list all technicians.');

        $technicians = User::where('role', UserRole::TECHNICIAN->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'technicians' => $technicians,
        ]);
    }

    private function ownerDashboard(): JsonResponse
    {
        $today = Carbon::today();
        $soonLimit = $today->copy()->addDays(30);
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $today->copy()->endOfWeek();

        $activeStatuses = [
            WorkOrderStatus::SCHEDULED->value,
            WorkOrderStatus::IN_PROGRESS->value,
        ];

        $followUpStatuses = [
            WorkOrderStatus::NEEDS_CONTACT->value,
            WorkOrderStatus::AWAITING_CUSTOMER->value,
        ];

        $overdueCount = MaintenancePlan::where('is_active', true)
            ->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '<', $today)
            ->count();

        $dueSoonCount = MaintenancePlan::where('is_active', true)
            ->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '>=', $today)
            ->whereDate('next_due_at', '<=', $soonLimit)
            ->count();

        $needsReviewCount = MaintenancePlan::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_due_at')
                    ->orWhere('interval_months', 0);
            })
            ->count();

        $brandAtRiskCount = MaintenancePlan::where('is_active', true)
            ->where('maintenance_type', 'BRAND')
            ->where(function ($q) use ($soonLimit) {
                $q->whereNull('next_due_at')
                    ->orWhereDate('next_due_at', '<=', $soonLimit);
            })
            ->whereDoesntHave('workOrders', fn($q) => $q->whereIn('status', $activeStatuses))
            ->count();

        $scheduledThisWeekCount = WorkOrder::whereIn('status', $activeStatuses)
            ->whereBetween('scheduled_start_at', [$weekStart, $weekEnd])
            ->count();

        $needsContactPlans = MaintenancePlan::with([
            'installation.customer',
            'workOrders' => fn($q) => $q->whereIn('status', $followUpStatuses)->latest(),
        ])
            ->where('is_active', true)
            ->whereDoesntHave('workOrders', fn($q) => $q->whereIn('status', $activeStatuses))
            ->where(function ($q) use ($soonLimit, $followUpStatuses) {
                $q->where(function ($sub) use ($soonLimit) {
                    $sub->whereNotNull('next_due_at')
                        ->whereDate('next_due_at', '<=', $soonLimit);
                })->orWhereHas('workOrders', fn($sub) => $sub->whereIn('status', $followUpStatuses));
            })
            ->orderByRaw("CASE WHEN maintenance_type = 'BRAND' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN next_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_due_at')
            ->limit(25)
            ->get();

        $needsContactList = $needsContactPlans->map(function (MaintenancePlan $plan) use ($today) {
            $openOrder = $plan->workOrders->first();
            $isOverdue = $plan->next_due_at !== null && $plan->next_due_at->lt($today);

            return [
                'plan_id' => $plan->id,
                'installation_id' => $plan->installation_id,
                'installation_code' => $plan->installation->external_id,
                'installation_name' => $plan->installation->name,
                'installation_type' => $plan->installation->installation_type,
                'is_fire_safety' => $plan->installation->isFireSafety(),
                'customer_name' => $plan->installation->customer->name,
                'customer_phone' => $plan->installation->customer->phone,
                'location_city' => $plan->installation->location_city,
                'last_completed_at' => $plan->last_completed_at?->toDateString(),
                'next_due_at' => $plan->next_due_at?->toDateString(),
                'is_overdue' => $isOverdue,
                'days_overdue' => $isOverdue ? (int) abs($plan->next_due_at->diffInDays($today)) : null,
                'open_work_order_id' => $openOrder?->id,
                'follow_up_status' => $openOrder?->status->value ?? WorkOrderStatus::NEEDS_CONTACT->value,
                'notes' => $openOrder?->notes ?? $plan->source_notes,
            ];
        });

        $weekCountsByTech = WorkOrder::whereIn('status', $activeStatuses)
            ->whereBetween('scheduled_start_at', [$weekStart, $weekEnd])
            ->whereNotNull('assigned_technician_id')
            ->selectRaw('assigned_technician_id, COUNT(*) as total')
            ->groupBy('assigned_technician_id')
            ->pluck('total', 'assigned_technician_id');

        $teamWeekLoad = User::where('role', UserRole::TECHNICIAN->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $tech) => [
                'id' => $tech->id,
                'name' => $tech->name,
                'week_visits' => (int) ($weekCountsByTech[$tech->id] ?? 0),
            ]);

        $upcomingWeekVisits = WorkOrder::with(['installation.customer', 'technician:id,name'])
            ->whereIn('status', $activeStatuses)
            ->where('scheduled_start_at', '>=', $today)
            ->orderBy('scheduled_start_at')
            ->limit(5)
            ->get()
            ->map(fn (WorkOrder $wo) => [
                'id' => $wo->id,
                'scheduled_start_at' => $wo->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $wo->scheduled_end_at?->toIso8601String(),
                'customer_name' => $wo->installation->customer->name,
                'installation_type' => $wo->installation->installation_type,
                'technician_name' => $wo->technician?->name ?? 'Unassigned',
            ]);

        return response()->json([
            'role' => 'owner',
            'kpis' => [
                'overdue_count' => $overdueCount,
                'due_soon_count' => $dueSoonCount,
                'brand_at_risk_count' => $brandAtRiskCount,
                'needs_review_count' => $needsReviewCount,
                'scheduled_this_week_count' => $scheduledThisWeekCount,
                'needs_contact_count' => $needsContactList->count(),
            ],
            'needs_contact_list' => $needsContactList,
            'team_week_load' => $teamWeekLoad,
            'upcoming_week_visits' => $upcomingWeekVisits,
        ]);
    }

    private function technicianDashboard(User $technician): JsonResponse
    {
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $today->copy()->endOfWeek();

        $activeStatuses = [
            WorkOrderStatus::SCHEDULED->value,
            WorkOrderStatus::IN_PROGRESS->value,
        ];

        $myUpcoming = WorkOrder::with(['installation.customer'])
            ->where('assigned_technician_id', $technician->id)
            ->whereIn('status', $activeStatuses)
            ->orderBy('scheduled_start_at')
            ->limit(15)
            ->get();

        $todayCount = WorkOrder::where('assigned_technician_id', $technician->id)
            ->whereIn('status', $activeStatuses)
            ->whereDate('scheduled_start_at', $today)
            ->count();

        $weekCount = WorkOrder::where('assigned_technician_id', $technician->id)
            ->whereIn('status', $activeStatuses)
            ->whereBetween('scheduled_start_at', [$weekStart, $weekEnd])
            ->count();

        $completedCount = WorkOrder::where('assigned_technician_id', $technician->id)
            ->where('status', WorkOrderStatus::COMPLETED->value)
            ->count();

        return response()->json([
            'role' => 'technician',
            'kpis' => [
                'today_count' => $todayCount,
                'week_count' => $weekCount,
                'completed_count' => $completedCount,
            ],
            'upcoming_work_orders' => $myUpcoming->map(fn(WorkOrder $wo) => [
                'id' => $wo->id,
                'external_id' => $wo->external_id,
                'status' => $wo->status->value,
                'scheduled_start_at' => $wo->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $wo->scheduled_end_at?->toIso8601String(),
                'installation_id' => $wo->installation_id,
                'installation_name' => $wo->installation->name,
                'installation_type' => $wo->installation->installation_type,
                'customer_name' => $wo->installation->customer->name,
                'customer_phone' => $wo->installation->customer->phone,
                'location_address' => trim(implode(', ', array_filter([
                    $wo->installation->location_street,
                    trim(($wo->installation->location_postal_code ?? '') . ' ' . ($wo->installation->location_city ?? '')),
                ]))),
            ]),
        ]);
    }
}