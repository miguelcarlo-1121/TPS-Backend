<?php

namespace App\Console\Commands;

use App\Models\BookingPayment;
use Illuminate\Console\Command;

class CompleteEndedAppointments extends Command
{
    protected $signature   = 'appointments:complete-ended';
    protected $description = 'Mark approved appointments whose end time has passed as completed.';

    public function handle(): int
    {
        $count = BookingPayment::completeEnded();
        $this->info("Marked {$count} appointment(s) as completed.");

        return self::SUCCESS;
    }
}