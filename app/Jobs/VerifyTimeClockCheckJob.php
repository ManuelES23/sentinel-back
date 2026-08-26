<?php
// sentinel-back/app/Jobs/VerifyTimeClockCheckJob.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyTimeClockCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $timeClockCheckId)
    {
    }

    public function handle(): void
    {
        // Implementado en Task 4.
    }
}
