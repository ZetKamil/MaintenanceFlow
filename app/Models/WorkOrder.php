<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'installation_id',
    'maintenance_plan_id',
    'assigned_technician_id',
    'created_by_id',
    'source_system',
    'external_id',
    'cycle_due_date',
    'status',
    'scheduled_start_at',
    'scheduled_end_at',
    'notes',
    'cancelled_reason',
])]
class WorkOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'cycle_due_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function report(): HasOne
    {
        return $this->hasOne(MaintenanceReport::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    /**
     * Check if technician already has an overlapping appointment.
     * Mathematical overlap formula: (start < existing_end) AND (end > existing_start)
     */
    public static function hasScheduleConflict(
        int $technicianId,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $ignoreWorkOrderId = null
    ): bool {
        return static::where('assigned_technician_id', $technicianId)
            ->whereNotIn('status', [WorkOrderStatus::CANCELLED->value, WorkOrderStatus::COMPLETED->value])
            ->when($ignoreWorkOrderId, fn($q) => $q->where('id', '!=', $ignoreWorkOrderId))
            ->where(function ($query) use ($start, $end) {
                $query->where('scheduled_start_at', '<', $end)
                    ->where('scheduled_end_at', '>', $start);
            })
            ->exists();
    }
}