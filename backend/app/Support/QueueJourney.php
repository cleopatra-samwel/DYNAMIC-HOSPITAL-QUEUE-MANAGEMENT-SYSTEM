<?php

namespace App\Support;

/**
 * Maps a queue ticket's status onto a simple patient journey-state string
 * for the parent visit, e.g. WAITING in Laboratory -> "WAITING_LAB",
 * CALLED/IN_SERVICE in Laboratory -> "IN_LAB".
 */
class QueueJourney
{
    public static function stateFor(string $deptCode, string $ticketStatus): string
    {
        return match ($ticketStatus) {
            'WAITING' => "WAITING_{$deptCode}",
            'CALLED', 'IN_SERVICE' => "IN_{$deptCode}",
            'COMPLETED' => 'COMPLETED',
            'CANCELLED', 'NO_SHOW' => 'EXITED',
            'ON_HOLD' => 'ON_HOLD',
            'TRANSFERRED' => 'TRANSFERRED',
            default => 'REGISTERED',
        };
    }
}
