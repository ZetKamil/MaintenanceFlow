<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Models\MaintenancePlan;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    /**
     * List work orders for the weekly planning calendar or backlog queue.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $user = $request->user();
        $query = WorkOrder::with(['installation.customer', 'technician:id,name', 'maintenancePlan']);

        // Technicians only see their own assigned work orders
        if ($user->isTechnician()) {
            $query->where('assigned_technician_id', $user->id);
        } elseif ($techId = $request->query('technician_id')) {
            $query->where('assigned_technician_id', (int) $techId);
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        $start = $request->query('start');
        $end = $request->query('end');

        if ($start && $end) {
            $rangeStart = Carbon::parse($start)->startOfDay();
            $rangeEnd = Carbon::parse($end)->endOfDay();

            $query->whereBetween('scheduled_start_at', [$rangeStart, $rangeEnd])
                ->orderBy('scheduled_start_at');
        } else {
            $query->orderByDesc('scheduled_start_at')
                ->orderByDesc('id')
                ->limit(100);
        }

        $orders = $query->get()->map(fn(WorkOrder $wo) => $this->formatWorkOrder($wo));

        return response()->json([
            'work_orders' => $orders,
        ]);
    }

    /**
     * Show single work order details with hardware and report history.
     */
    public function show(WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('view', $workOrder);

        $workOrder->load([
            'installation.customer',
            'installation.components',
            'maintenancePlan',
            'technician:id,name',
            'report.submittedBy:id,name',
            'materials',
        ]);

        return response()->json([
            'work_order' => $this->formatWorkOrder($workOrder, detailed: true),
        ]);
    }

    /**
     * Create and optionally schedule a new work order.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', WorkOrder::class);

        $validated = $request->validate([
            'installation_id' => ['required', 'exists:installations,id'],
            'maintenance_plan_id' => ['nullable', 'exists:maintenance_plans,id'],
            'assigned_technician_id' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
            'status' => [
                'required',
                Rule::in([
                    WorkOrderStatus::NEEDS_CONTACT->value,
                    WorkOrderStatus::AWAITING_CUSTOMER->value,
                    WorkOrderStatus::SCHEDULED->value,
                ])
            ],
            'scheduled_start_at' => ['nullable', 'date', 'required_if:status,scheduled'],
            'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_start_at', 'required_if:status,scheduled'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (!empty($validated['maintenance_plan_id'])) {
            $plan = MaintenancePlan::find($validated['maintenance_plan_id']);

            $hasActiveOrder = WorkOrder::where('maintenance_plan_id', $plan->id)
                ->whereIn('status', [WorkOrderStatus::SCHEDULED->value, WorkOrderStatus::IN_PROGRESS->value])
                ->exists();

            if ($hasActiveOrder) {
                throw ValidationException::withMessages([
                    'maintenance_plan_id' => ['An active scheduled work order already exists for this maintenance plan.'],
                ]);
            }
        }

        if ($validated['status'] === WorkOrderStatus::SCHEDULED->value) {
            if (empty($validated['assigned_technician_id'])) {
                throw ValidationException::withMessages([
                    'assigned_technician_id' => ['Please assign a technician when scheduling an appointment.'],
                ]);
            }

            $this->ensureNoScheduleConflict(
                (int) $validated['assigned_technician_id'],
                Carbon::parse($validated['scheduled_start_at']),
                Carbon::parse($validated['scheduled_end_at'])
            );
        }

        $planCycleDate = !empty($validated['maintenance_plan_id'])
            ? MaintenancePlan::find($validated['maintenance_plan_id'])?->next_due_at
            : null;

        $workOrder = WorkOrder::create([
            'installation_id' => $validated['installation_id'],
            'maintenance_plan_id' => $validated['maintenance_plan_id'] ?? null,
            'assigned_technician_id' => $validated['assigned_technician_id'] ?? null,
            'created_by_id' => $request->user()->id,
            'cycle_due_date' => $planCycleDate,
            'status' => $validated['status'],
            'scheduled_start_at' => $validated['scheduled_start_at'] ?? null,
            'scheduled_end_at' => $validated['scheduled_end_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $workOrder->load(['installation.customer', 'technician:id,name', 'maintenancePlan']);

        return response()->json([
            'message' => 'Work order created successfully.',
            'work_order' => $this->formatWorkOrder($workOrder),
        ], 201);
    }

    /**
     * Reschedule, reassign technician, update follow-up status, or cancel a work order.
     */
    public function update(Request $request, WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        if ($workOrder->status === WorkOrderStatus::COMPLETED) {
            throw ValidationException::withMessages([
                'status' => ['Completed work orders are locked for audit integrity and cannot be modified.'],
            ]);
        }

        $validated = $request->validate([
            'assigned_technician_id' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
            'status' => [
                'required',
                Rule::in([
                    WorkOrderStatus::NEEDS_CONTACT->value,
                    WorkOrderStatus::AWAITING_CUSTOMER->value,
                    WorkOrderStatus::SCHEDULED->value,
                    WorkOrderStatus::CANCELLED->value,
                ])
            ],
            'scheduled_start_at' => ['nullable', 'date', 'required_if:status,scheduled'],
            'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_start_at', 'required_if:status,scheduled'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cancelled_reason' => ['nullable', 'string', 'max:500', 'required_if:status,cancelled'],
        ]);

        if ($validated['status'] === WorkOrderStatus::SCHEDULED->value) {
            if (empty($validated['assigned_technician_id'])) {
                throw ValidationException::withMessages([
                    'assigned_technician_id' => ['Please assign a technician when scheduling an appointment.'],
                ]);
            }

            $this->ensureNoScheduleConflict(
                (int) $validated['assigned_technician_id'],
                Carbon::parse($validated['scheduled_start_at']),
                Carbon::parse($validated['scheduled_end_at']),
                $workOrder->id
            );
        }

        $workOrder->update([
            'assigned_technician_id' => $validated['assigned_technician_id'] ?? $workOrder->assigned_technician_id,
            'status' => $validated['status'],
            'scheduled_start_at' => $validated['status'] === WorkOrderStatus::SCHEDULED->value ? $validated['scheduled_start_at'] : null,
            'scheduled_end_at' => $validated['status'] === WorkOrderStatus::SCHEDULED->value ? $validated['scheduled_end_at'] : null,
            'notes' => $validated['notes'] ?? $workOrder->notes,
            'cancelled_reason' => $validated['cancelled_reason'] ?? $workOrder->cancelled_reason,
        ]);

        $workOrder->load(['installation.customer', 'technician:id,name', 'maintenancePlan']);

        return response()->json([
            'message' => 'Work order updated successfully.',
            'work_order' => $this->formatWorkOrder($workOrder),
        ]);
    }

    /**
     * Mark a scheduled visit as in-progress when the technician arrives on site.
     */
    public function start(WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('complete', $workOrder);

        if ($workOrder->status !== WorkOrderStatus::SCHEDULED) {
            throw ValidationException::withMessages([
                'status' => ['Only scheduled work orders can be started.'],
            ]);
        }

        $workOrder->update([
            'status' => WorkOrderStatus::IN_PROGRESS,
        ]);

        return response()->json([
            'message' => 'Work order is now in progress.',
            'status' => $workOrder->status->value,
        ]);
    }

    private function ensureNoScheduleConflict(int $technicianId, Carbon $start, Carbon $end, ?int $ignoreId = null): void
    {
        if (WorkOrder::hasScheduleConflict($technicianId, $start, $end, $ignoreId)) {
            throw ValidationException::withMessages([
                'scheduled_start_at' => ['Schedule conflict: this technician already has an overlapping appointment in this time slot.'],
            ]);
        }
    }

    private function formatWorkOrder(WorkOrder $wo, bool $detailed = false): array
    {
        $data = [
            'id' => $wo->id,
            'external_id' => $wo->external_id,
            'status' => $wo->status->value,
            'status_label' => $wo->status->label(),
            'is_terminal' => $wo->status->isTerminal(),
            'cycle_due_date' => $wo->cycle_due_date?->toDateString(),
            'scheduled_start_at' => $wo->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $wo->scheduled_end_at?->toIso8601String(),
            'notes' => $wo->notes,
            'cancelled_reason' => $wo->cancelled_reason,
            'technician' => $wo->technician,
            'installation' => [
                'id' => $wo->installation->id,
                'external_id' => $wo->installation->external_id,
                'name' => $wo->installation->name,
                'installation_type' => $wo->installation->installation_type,
                'is_fire_safety' => $wo->installation->isFireSafety(),
                'location_street' => $wo->installation->location_street,
                'location_postal_code' => $wo->installation->location_postal_code,
                'location_city' => $wo->installation->location_city,
                'customer_name' => $wo->installation->customer->name,
                'customer_phone' => $wo->installation->customer->phone,
            ],
            'maintenance_plan' => $wo->maintenancePlan ? [
                'id' => $wo->maintenancePlan->id,
                'interval_months' => $wo->maintenancePlan->interval_months,
                'next_due_at' => $wo->maintenancePlan->next_due_at?->toDateString(),
            ] : null,
        ];

        if ($detailed) {
            $data['components'] = $wo->installation->components;
            $data['report'] = $wo->report;
            $data['materials'] = $wo->materials;
        }

        return $data;
    }
}