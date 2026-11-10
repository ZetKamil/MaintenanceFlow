<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MaintenanceReportController extends Controller
{
    /**
     * Submit a technician service report, log used materials, complete the work order,
     * and roll the maintenance plan forward atomically inside a database transaction.
     */
    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('complete', $workOrder);

        if ($workOrder->status->isTerminal() || $workOrder->report()->exists()) {
            throw ValidationException::withMessages([
                'work_order' => ['This work order is already closed or already has a submitted service report.'],
            ]);
        }

        $validated = $request->validate([
            'performed_at' => ['nullable', 'date'],
            'performed_work' => ['required', 'string', 'max:5000'],
            'findings' => ['nullable', 'string', 'max:3000'],
            'recommendations' => ['nullable', 'string', 'max:3000'],
            'next_due_at' => ['nullable', 'date'],
            'materials' => ['nullable', 'array'],
            'materials.*.material_code' => ['nullable', 'string', 'max:50'],
            'materials.*.description' => ['required_with:materials', 'string', 'max:255'],
            'materials.*.quantity' => ['required_with:materials', 'numeric', 'min:0.01', 'max:9999'],
        ]);

        $performedAt = !empty($validated['performed_at'])
            ? Carbon::parse($validated['performed_at'])
            : Carbon::now();

        $plan = $workOrder->maintenancePlan;

        // Calculate next due date from explicit technician choice or confirmed plan interval
        $nextDueAt = null;
        if (!empty($validated['next_due_at'])) {
            $nextDueAt = Carbon::parse($validated['next_due_at']);
        } elseif ($plan && $plan->interval_months > 0) {
            $nextDueAt = $performedAt->copy()->addMonths($plan->interval_months);
        }

        $report = DB::transaction(function () use ($request, $workOrder, $plan, $validated, $performedAt, $nextDueAt) {
            $createdReport = $workOrder->report()->create([
                'submitted_by_id' => $request->user()->id,
                'performed_at' => $performedAt,
                'performed_work' => $validated['performed_work'],
                'findings' => $validated['findings'] ?? null,
                'recommendations' => $validated['recommendations'] ?? null,
                'next_due_at' => $nextDueAt?->toDateString(),
            ]);

            if (!empty($validated['materials'])) {
                foreach ($validated['materials'] as $material) {
                    $workOrder->materials()->create([
                        'material_code' => $material['material_code'] ?? null,
                        'description' => $material['description'],
                        'quantity' => $material['quantity'],
                    ]);
                }
            }

            $workOrder->update([
                'status' => WorkOrderStatus::COMPLETED,
            ]);

            if ($plan) {
                $planUpdate = [
                    'last_completed_at' => $performedAt->toDateString(),
                ];

                if ($nextDueAt) {
                    $planUpdate['next_due_at'] = $nextDueAt->toDateString();
                }

                $plan->update($planUpdate);
            }

            return $createdReport;
        });

        $workOrder->load(['maintenancePlan', 'materials', 'report.submittedBy:id,name']);

        return response()->json([
            'message' => 'Service report submitted and maintenance cycle updated.',
            'report' => $workOrder->report,
            'work_order_status' => $workOrder->status->value,
            'next_due_at' => $workOrder->maintenancePlan?->next_due_at?->toDateString(),
        ], 201);
    }
}