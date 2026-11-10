<?php

namespace App\Enums;

enum UserRole: string
{
    case OWNER = 'owner';
    case TECHNICIAN = 'technician';

    /**
     * Human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner / Manager',
            self::TECHNICIAN => 'Field Technician',
        };
    }
}