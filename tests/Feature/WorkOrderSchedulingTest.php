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

class WorkOrderSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $tom;
    private User $wout;
    private Installation $installationA;
    private Installation $installationB;
    private MaintenancePlan $planA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::OWNER, 'is_active' => true]);
        $this->tom = User::factory()->create(['role' => UserRole::TECHNICIAN, 'is_active' => true]);
        $this->wout = User::factory()->create(['role' => UserRole::TECHNICIAN, 'is_active' => true]);

        $customer = Customer::create(['name' => 'Dierenartsenpraktijk De Leie', 'is_active' => true]);

        $this->installationA = Installation::create([
            'customer_id' => $customer->id,
            'name' => 'Fire System',
            'installation_type' => 'BRAND',
            'is_active' => true,
        ]);

        $this->installationB = Installation::create([
            'customer_id' => $customer->id,
            'name' => 'Camera System',
            'installation_type' => 'CAM',
            'is_active' => true,
        ]);

        $this->planA = MaintenancePlan::create([
            'installation_id' => $this->installationA->id,
            'maintenance_type' => 'BRAND',
            'interval_months' => 12,
            'next_due_at' => '2026-11-15',
            'is_active' => true,
        ]);
    }

    public function test_it_prevents_overlapping_appointments_and_inverted_times(): void
    {
        $this->actingAs($this->owner);

        // Inverted times rejected (end <= start)
        $this->postJson('/api/work-orders', [
            'installation_id' => $this->installationA->id,
            'assigned_technician_id' => $this->tom->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-16 13:00:00',
            'scheduled_end_at' => '2026-11-16 10:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_end_at']);

        // Valid visit booked for Tom (09:00 - 11:00)
        $this->postJson('/api/work-orders', [
            'installation_id' => $this->installationA->id,
            'maintenance_plan_id' => $this->planA->id,
            'assigned_technician_id' => $this->tom->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-16 09:00:00',
            'scheduled_end_at' => '2026-11-16 11:00:00',
        ])->assertStatus(201);

        // Overlapping slot for Tom (10:30 - 12:00) rejected
        $this->postJson('/api/work-orders', [
            'installation_id' => $this->installationB->id,
            'assigned_technician_id' => $this->tom->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-16 10:30:00',
            'scheduled_end_at' => '2026-11-16 12:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_start_at']);

        // Duplicate active work order for the same maintenance plan rejected
        $this->postJson('/api/work-orders', [
            'installation_id' => $this->installationA->id,
            'maintenance_plan_id' => $this->planA->id,
            'assigned_technician_id' => $this->wout->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-17 09:00:00',
            'scheduled_end_at' => '2026-11-17 11:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['maintenance_plan_id']);

        // Back-to-back non-overlapping slot (11:00 - 13:00) for Tom succeeds
        $this->postJson('/api/work-orders', [
            'installation_id' => $this->installationB->id,
            'assigned_technician_id' => $this->tom->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-16 11:00:00',
            'scheduled_end_at' => '2026-11-16 13:00:00',
        ])->assertStatus(201);
    }

    public function test_rescheduling_allows_shifting_own_slot_and_completed_orders_are_locked(): void
    {
        $this->actingAs($this->owner);

        $order = WorkOrder::create([
            'installation_id' => $this->installationA->id,
            'assigned_technician_id' => $this->tom->id,
            'created_by_id' => $this->owner->id,
            'status' => WorkOrderStatus::SCHEDULED,
            'scheduled_start_at' => '2026-11-18 09:00:00',
            'scheduled_end_at' => '2026-11-18 11:00:00',
        ]);

        // Shift by 30 minutes (overlaps its own previous time, must succeed)
        $this->putJson("/api/work-orders/{$order->id}", [
            'assigned_technician_id' => $this->tom->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-18 09:30:00',
            'scheduled_end_at' => '2026-11-18 11:30:00',
        ])->assertOk();

        // Completed work orders cannot be modified
        $completed = WorkOrder::create([
            'installation_id' => $this->installationB->id,
            'assigned_technician_id' => $this->tom->id,
            'created_by_id' => $this->owner->id,
            'status' => WorkOrderStatus::COMPLETED,
            'scheduled_start_at' => '2026-11-10 09:00:00',
            'scheduled_end_at' => '2026-11-10 11:00:00',
        ]);

        $this->putJson("/api/work-orders/{$completed->id}", [
            'assigned_technician_id' => $this->wout->id,
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-11-19 09:00:00',
            'scheduled_end_at' => '2026-11-19 11:00:00',
        ])->assertStatus(422);
    }
}

