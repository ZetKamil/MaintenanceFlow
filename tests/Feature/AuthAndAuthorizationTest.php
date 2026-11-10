<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_return_401(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
        $this->getJson('/api/installations')->assertStatus(401);
        $this->getJson('/api/work-orders')->assertStatus(401);
    }

    public function test_active_user_can_log_in_and_inactive_user_is_rejected(): void
    {
        $active = User::factory()->create([
            'email' => 'dirk@verlinde.be',
            'password' => 'secret123',
            'role' => UserRole::OWNER,
            'is_active' => true,
        ]);

        $inactive = User::factory()->create([
            'email' => 'former@verlinde.be',
            'password' => 'secret123',
            'role' => UserRole::TECHNICIAN,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $inactive->email,
            'password' => 'secret123',
        ])->assertStatus(422);

        $response = $this->postJson('/api/login', [
            'email' => $active->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'dirk@verlinde.be')
            ->assertJsonPath('user.role', 'owner');

        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $active->id);
    }

    public function test_technician_cannot_access_owner_routes_or_another_technicians_work_order(): void
    {
        $owner = User::factory()->create(['role' => UserRole::OWNER, 'is_active' => true]);
        $tom = User::factory()->create(['role' => UserRole::TECHNICIAN, 'is_active' => true]);
        $wout = User::factory()->create(['role' => UserRole::TECHNICIAN, 'is_active' => true]);

        $customer = Customer::create(['name' => 'Opticien Desmet', 'is_active' => true]);
        $tomsSite = Installation::create([
            'customer_id' => $customer->id,
            'name' => 'Fire Panel Site A',
            'installation_type' => 'BRAND',
            'is_active' => true,
        ]);
        $woutsSite = Installation::create([
            'customer_id' => $customer->id,
            'name' => 'Alarm Site B',
            'installation_type' => 'INBR',
            'is_active' => true,
        ]);

        $tomsOrder = WorkOrder::create([
            'installation_id' => $tomsSite->id,
            'assigned_technician_id' => $tom->id,
            'created_by_id' => $owner->id,
            'status' => WorkOrderStatus::SCHEDULED,
            'scheduled_start_at' => '2026-11-12 09:00:00',
            'scheduled_end_at' => '2026-11-12 11:00:00',
        ]);

        $woutsOrder = WorkOrder::create([
            'installation_id' => $woutsSite->id,
            'assigned_technician_id' => $wout->id,
            'created_by_id' => $owner->id,
            'status' => WorkOrderStatus::SCHEDULED,
            'scheduled_start_at' => '2026-11-12 09:00:00',
            'scheduled_end_at' => '2026-11-12 11:00:00',
        ]);

        // Act as Tom (Technician)
        $this->actingAs($tom);

        // Owner-only endpoints return 403
        $this->getJson('/api/installations')->assertStatus(403);
        $this->getJson('/api/technicians')->assertStatus(403);
        $this->postJson('/api/work-orders', [])->assertStatus(403);
        $this->putJson("/api/work-orders/{$tomsOrder->id}", [])->assertStatus(403);

        // IDOR checks: Tom cannot view, start, complete Wout's order or Wout's unassigned installation
        $this->getJson("/api/work-orders/{$woutsOrder->id}")->assertStatus(403);
        $this->postJson("/api/work-orders/{$woutsOrder->id}/start")->assertStatus(403);
        $this->postJson("/api/work-orders/{$woutsOrder->id}/report", [
            'performed_work' => 'Unauthorized attempt',
        ])->assertStatus(403);
        $this->getJson("/api/installations/{$woutsSite->id}")->assertStatus(403);

        // Tom CAN view his own order and its installation dossier
        $this->getJson("/api/work-orders/{$tomsOrder->id}")->assertOk();
        $this->getJson("/api/installations/{$tomsSite->id}")->assertOk();

        // Owner CAN access owner endpoints and view any order
        $this->actingAs($owner);
        $this->getJson('/api/installations')->assertOk();
        $this->getJson('/api/technicians')->assertOk();
        $this->getJson("/api/work-orders/{$woutsOrder->id}")->assertOk();
    }
}

