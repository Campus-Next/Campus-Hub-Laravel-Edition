<?php

namespace App\Console\Commands;

use App\Models\EventAbsentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessEventAbsentSchedules extends Command
{
    protected $signature = 'events:process-absent-schedules';

    protected $description = 'Mark registered participants as absent for completed events.';

    public function handle(): int
    {
        $processedSchedules = 0;
        $updatedParticipants = 0;

        EventAbsentSchedule::query()
            ->whereNull('processed_at')
            ->whereNull('cancelled_at')
            ->where('run_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($schedules) use (&$processedSchedules, &$updatedParticipants) {
                foreach ($schedules as $schedule) {
                    DB::transaction(function () use ($schedule, &$processedSchedules, &$updatedParticipants) {
                        $lockedSchedule = EventAbsentSchedule::query()
                            ->whereKey($schedule->id)
                            ->lockForUpdate()
                            ->first();

                        if (
                            !$lockedSchedule ||
                            $lockedSchedule->processed_at ||
                            $lockedSchedule->cancelled_at ||
                            $lockedSchedule->run_at->isFuture()
                        ) {
                            return;
                        }

                        $event = $lockedSchedule->event()->first();

                        if (!$event) {
                            $lockedSchedule->delete();
                            return;
                        }

                        $updated = $event->participants()
                            ->where('status', 'registered')
                            ->update(['status' => 'absent']);

                        $lockedSchedule->update(['processed_at' => now()]);

                        $processedSchedules++;
                        $updatedParticipants += $updated;
                    });
                }
            });

        $this->info("Processed {$processedSchedules} event absent schedule(s); marked {$updatedParticipants} participant(s) absent.");

        return self::SUCCESS;
    }
}
