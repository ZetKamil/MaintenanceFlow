<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_order_id',
    'submitted_by_id',
    'performed_at',
    'performed_work',
    'findings',
    'recommendations',
    'next_due_at',
])]
class MaintenanceReport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'next_due_at' => 'date',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }
}