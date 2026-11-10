<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceReportTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_visit_saves_report_materials_and_rolls_cycle_forward_atomically(): void
    {
        $owner = User::factory()->create(['role' => UserRole::OWNER, 'is_active' => true]);
        $tom = User::factory()->create(['role' => UserRole::TECHNICIAN, 'is_active' => true]);

        $customer = Customer::create(['name' => 'Bakkerij Verhaeghe', 'is_active' => true]);
        $installation = Installation::create([
            'customer_id' => $customer->id,
            'name' => 'Fire Alarm Bakkerij',
            'installation_type' => 'BRAND',
            'is_active' => true,
        ]);
        $plan = MaintenancePlan::create([
            'installation_id' => $installation->id,
            'maintenance_type' => 'BRAND',
            'interval_months' => 12,
            'next_due_at' => '2026-11-01',
            'is_active' => true,
        ]);
        $workOrder = WorkOrder::create([
            'installation_id' => $installation->id,
            'maintenance_plan_id' => $plan->id,
            'assigned_technician_id' => $tom->id,
            'created_by_id' => $owner->id,
            'status' => WorkOrderStatus::SCHEDULED,
            'scheduled_start_at' => '2026-11-10 09:00:00',
            'scheduled_end_at' => '2026-11-10 11:00:00',
        ]);

        $this->actingAs($tom);

        // Start visit
        $this->postJson("/api/work-orders/{$workOrder->id}/start")
            ->assertOk()
            ->assertJsonPath('status', 'in_progress');

        // Cannot start twice
        $this->postJson("/api/work-orders/{$workOrder->id}/start")
            ->assertStatus(422);

        // Invalid material payload must not mutate work order state
        $this->postJson("/api/work-orders/{$workOrder->id}/report", [
            'performed_work' => 'Tested all optical detectors.',
            'materials' => [
                ['description' => '12V Battery', 'quantity' => 0],
            ],
        ])->assertStatus(422);
        $this->assertSame(WorkOrderStatus::IN_PROGRESS, $workOrder->fresh()->status);

        // Valid service report submission rolls maintenance cycle forward
        $this->postJson("/api/work-orders/{$workOrder->id}/report", [
            'performed_at' => '2026-11-10',
            'performed_work' => 'Tested all optical detectors and replaced backup battery.',
            'findings' => 'Battery voltage low (10.8V).',
            'recommendations' => 'Monitor zone 2 siren next year.',
            'materials' => [
                ['material_code' => 'BAT-12V7', 'description' => '12V 7Ah Battery', 'quantity' => 1.5],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('work_order_status', 'completed');

        $workOrder->refresh();
        $plan->refresh();

        $this->assertSame(WorkOrderStatus::COMPLETED, $workOrder->status);
        $this->assertSame('2026-11-10', $plan->last_completed_at->toDateString());
        $this->assertSame('2027-11-10', $plan->next_due_at->toDateString());
        $this->assertDatabaseHas('work_order_materials', [
            'work_order_id' => $workOrder->id,
            'material_code' => 'BAT-12V7',
            'description' => '12V 7Ah Battery',
        ]);

        // Duplicate report submission must be blocked
        $this->postJson("/api/work-orders/{$workOrder->id}/report", [
            'performed_work' => 'Duplicate click',
        ])->assertStatus(422);
    }
}

