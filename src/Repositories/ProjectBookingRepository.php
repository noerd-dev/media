<?php

namespace Nywerk\Media\Repositories;

use Illuminate\Support\Facades\Auth;
use Nywerk\HarvesterProject\Models\ProjectBooking;

class ProjectBookingRepository
{
    public function getProjectFirewoodBookings(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $projectBookings = ProjectBooking::with('project')->whereHas('project', function ($relationQuery): void {
            $relationQuery->where('tenant_id', Auth::user()->selected_tenant_id);
        })->where('type', 'BRENNHOLZ')
            ->where('firewood_type', 'WOOD')
            ->orderBy('id', 'desc')
            ->paginate(25);

        foreach ($projectBookings as $booking) {
            $booking->brennholz = $booking->brennholz * -1;
            $booking->brennholz_2 = $booking->brennholz_2 * -1;
            $booking->brennholz_2_5 = $booking->brennholz_2_5 * -1;
            $booking->brennholz_3 = $booking->brennholz_3 * -1;
            $booking->brennholz_4 = $booking->brennholz_4 * -1;
        }

        return $projectBookings;
    }
}
