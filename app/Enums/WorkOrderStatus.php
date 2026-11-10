<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case NEEDS_CONTACT = 'needs_contact';
    case AWAITING_CUSTOMER = 'awaiting_customer';
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Human-readable label for UI badges.
     */
    public function label(): string
    {
        return match ($this) {
            self::NEEDS_CONTACT => 'Needs Contact',
            self::AWAITING_CUSTOMER => 'Awaiting Customer',
            self::SCHEDULED => 'Scheduled',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Whether this status represents a finished/closed order.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}