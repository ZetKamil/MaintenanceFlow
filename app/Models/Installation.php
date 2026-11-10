<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'source_system',
    'external_id',
    'name',
    'installation_type',
    'location_street',
    'location_postal_code',
    'location_city',
    'is_active',
])]
class Installation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Installation belongs to a customer.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * An installation can have recurring maintenance plans.
     */
    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }

    /**
     * An installation can have work orders.
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Hardware components installed on site (panels, detectors, batteries).
     */
    public function components(): HasMany
    {
        return $this->hasMany(InstallationComponent::class);
    }

    /**
     * Helper to identify high-priority statutory fire systems.
     */
    public function isFireSafety(): bool
    {
        return strtoupper((string) $this->installation_type) === 'BRAND';
    }
}