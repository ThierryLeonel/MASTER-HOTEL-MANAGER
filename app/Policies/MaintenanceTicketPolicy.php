<?php

namespace App\Policies;

use App\Models\MaintenanceTicket;
use App\Models\User;

class MaintenanceTicketPolicy
{
    public function create(User $user): bool
    {
        return $user->can('report-maintenance');
    }

    public function approve(User $user, MaintenanceTicket $ticket): bool
    {
        return $user->can('approve-maintenance');
    }

    public function resolve(User $user, MaintenanceTicket $ticket): bool
    {
        return $user->can('resolve-maintenance');
    }
}
