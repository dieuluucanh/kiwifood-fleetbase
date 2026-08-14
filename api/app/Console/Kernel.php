<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Refresh the Driver Activity report table:
        //  - nightly rollup of the previous (completed) day
        //  - hourly in-progress refresh of today so far
        $schedule->command('driver-activity:aggregate')->dailyAt('01:30')->withoutOverlapping();
        $schedule->command('driver-activity:aggregate --date=today')->hourlyAt(15)->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
