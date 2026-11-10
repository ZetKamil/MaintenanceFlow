<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    /**
     * Both owner and technicians can view the work orders list
     * (controller filters technicians to their own assignments).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * Owner sees all work orders; technician sees only assigned ones.
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOwner() || $workOrder->assigned_technician_id === $user->id;
    }

    /**
     * Only the owner / office can create and dispatch work orders.
     */
    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    /**
     * Only the owner / office can reschedule or reassign technicians.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOwner();
    }

    /**
     * Owner or the assigned technician can progress and complete a service report.
     */
    public function complete(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOwner() || $workOrder->assigned_technician_id === $user->id;
    }

    /**
     * Only the owner / office can cancel or delete work orders.
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOwner();
    }
}