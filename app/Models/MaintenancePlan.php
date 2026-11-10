<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'installation_id',
    'source_system',
    'external_id',
    'maintenance_type',
    'interval_months',
    'last_completed_at',
    'next_due_at',
    'is_active',
    'source_notes',
])]
class MaintenancePlan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'last_completed_at' => 'date',
            'next_due_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Check if maintenance is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->next_due_at !== null && $this->next_due_at->isPast();
    }

    /**
     * Check if maintenance is due within given days (default: 30).
     */
    public function isDueSoon(int $days = 30): bool
    {
        return $this->next_due_at !== null &&
            !$this->isOverdue() &&
            $this->next_due_at->lte(Carbon::today()->addDays($days));
    }
}