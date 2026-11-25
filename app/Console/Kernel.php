<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // The device pushes punches over ADMS (routes/iclock.php), so the old every-minute UDP pull is off.
        // To pull from a device in the old way instead, run `php artisan attendance:sync` or re-enable:
        // $schedule->command('attendance:sync')->everyMinute()->withoutOverlapping()->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
