<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_system',
    'external_id',
    'name',
    'contact_person',
    'email',
    'phone',
    'street',
    'postal_code',
    'city',
    'is_active',
])]
class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * A customer can have multiple installations.
     */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }
}