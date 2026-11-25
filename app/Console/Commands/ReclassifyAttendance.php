<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Services\PunchClassifier;
use Illuminate\Console\Command;

/**
 * Applies the current time rules to punches already stored, e.g. after changing ATTENDANCE_* times.
 */
class ReclassifyAttendance extends Command
{
    protected $signature = 'attendance:reclassify
                            {--from= : Only punches on or after this date (YYYY-MM-DD)}
                            {--dry-run : Show how many would change without saving}';

    protected $description = 'Re-apply the check-in / check-out / late / early-leave time rules to stored punches';

    public function handle(PunchClassifier $classifier): int
    {
        $from = $this->option('from');
        if ($from !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $this->error('--from must be a date like 2026-11-01');

            return self::FAILURE;
        }

        $statusByType = array_flip(config('zkteco.punch_types')); // check_in => 0, ...
        $changed = 0;
        $checked = 0;

        AttendanceLog::query()
            ->when($from, fn ($q) => $q->where('punch_time', '>=', $from . ' 00:00:00'))
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($classifier, $statusByType, &$changed, &$checked) {
                foreach ($logs as $log) {
                    $checked++;
                    // Older rows have no device_status: work it out from the type they were stored with
                    $deviceStatus = $log->device_status ?? $statusByType[$log->punch_type] ?? 0;
                    $result = $classifier->classify($log->punch_time, (int) $deviceStatus);

                    if ($result['punch_type'] === $log->punch_type && $result['attendance_status'] === $log->attendance_status && $log->device_status !== null) {
                        continue;
                    }

                    $changed++;
                    if (! $this->option('dry-run')) {
                        $log->forceFill($result + ['device_status' => $deviceStatus])->save();
                    }
                }
            });

        $this->info(($this->option('dry-run') ? 'Would update' : 'Updated') . " {$changed} of {$checked} punch(es).");

        return self::SUCCESS;
    }
}
