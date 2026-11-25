<?php

namespace App\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Decides a punch's type from the time of day, because face devices usually send "check-in" for every punch.
 *
 *   up to and including check_in_until (12:00)  -> check_in;  "late" if after late_after (09:30)
 *   after check_in_until                        -> check_out; "early_leave" if before check_out_from (18:00)
 *
 * Break and overtime keys (device status 2-5) are a deliberate choice on the device, so they are kept.
 * Settings: config/zkteco.php "time_rules".
 */
class PunchClassifier
{
    /** Device status codes the time rules may replace: 0 = check-in, 1 = check-out (the device defaults). */
    private const OVERRIDABLE_STATUSES = [0, 1];

    public function __construct(private ?array $rules = null)
    {
        $this->rules ??= config('zkteco.time_rules');
    }

    /**
     * @return array{punch_type: string, attendance_status: ?string}
     */
    public function classify(CarbonInterface $time, int $deviceStatus): array
    {
        $deviceType = config('zkteco.punch_types')[$deviceStatus] ?? 'check_in';

        if (! ($this->rules['enabled'] ?? false) || ! in_array($deviceStatus, self::OVERRIDABLE_STATUSES, true)) {
            return ['punch_type' => $deviceType, 'attendance_status' => null];
        }

        $clock = $time->format('H:i:s');

        if ($clock <= $this->time('check_in_until')) {
            return [
                'punch_type'        => 'check_in',
                'attendance_status' => $clock > $this->time('late_after') ? 'late' : null,
            ];
        }

        return [
            'punch_type'        => 'check_out',
            'attendance_status' => $clock < $this->time('check_out_from') ? 'early_leave' : null,
        ];
    }

    /**
     * A configured time as "HH:MM:SS" ("9:30" and "09:30" both work).
     */
    private function time(string $key): string
    {
        $value = (string) ($this->rules[$key] ?? '');

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            throw new InvalidArgumentException("zkteco.time_rules.{$key} must be a time like 09:30, got \"{$value}\".");
        }

        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }
}
