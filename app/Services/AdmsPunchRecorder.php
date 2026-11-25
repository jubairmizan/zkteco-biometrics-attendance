<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Throwable;

/**
 * Parses an ADMS ATTLOG upload and stores each punch in attendance_logs.
 */
class AdmsPunchRecorder
{
    /** @var array<string, ?int> PIN => users.id, for this upload only */
    private array $userIds = [];

    /**
     * @return array{accepted: int, stored: array<int, AttendanceLog>, duplicates: int, malformed: int, failed: int}
     *   accepted   lines the device can forget about (stored + duplicates)
     *   stored     punches that were new
     *   malformed  lines that can never be stored (reported, then dropped)
     *   failed     lines that hit an error while saving (the device should send them again)
     */
    public function record(Device $device, ?string $ip, string $body): array
    {
        $result = ['accepted' => 0, 'stored' => [], 'duplicates' => 0, 'malformed' => 0, 'failed' => 0];
        $punchTypes = config('zkteco.punch_types');
        $verifyTypes = config('zkteco.verification_types');

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $punch = $this->parse($line);

            if ($punch === null) {
                $result['malformed']++;
                ErrorNotifier::report(new \UnexpectedValueException(
                    "ZKTeco ADMS: malformed ATTLOG line from device {$device->serial_number}: " . json_encode($line)
                ));
                continue;
            }

            try {
                // Log to storage/logs/laravel.log for instant verification
                Log::info('ZKTeco Punch', [
                    'sn'          => $device->serial_number,
                    'pin'         => $punch['pin'],
                    'punched_at'  => $punch['time']->format('Y-m-d H:i:s'),
                    'status'      => $punch['status'],
                    'verify_mode' => $punch['verify'],
                ]);

                $attributes = [
                    'device_sn'      => $device->serial_number,
                    'device_user_id' => $punch['pin'],
                    'punch_time'     => $punch['time'],
                ];

                if (AttendanceLog::where($attributes)->exists()) {
                    $result['duplicates']++;
                    $result['accepted']++;
                    continue;
                }

                $log = AttendanceLog::create($attributes + [
                    'user_id'           => $this->userIdForPin($punch['pin']),
                    'device_ip'         => $ip ?? $device->ip_address ?? 'unknown',
                    'punch_type'        => $punchTypes[(int) $punch['status']] ?? 'check_in',
                    'verification_type' => $verifyTypes[(int) $punch['verify']] ?? "mode_{$punch['verify']}",
                    'work_code'         => is_numeric($punch['work_code']) ? (int) $punch['work_code'] : null,
                    'is_processed'      => false,
                ]);

                // Every PIN that punches gets an employee row, so it can be named on the dashboard
                Employee::firstOrCreate(['pin' => $punch['pin']], ['device_sn' => $device->serial_number]);

                $result['stored'][] = $log;
                $result['accepted']++;
            } catch (Throwable $e) {
                if ($this->isDuplicateKey($e)) {
                    // Same punch inserted by a parallel request between the check and the insert
                    $result['duplicates']++;
                    $result['accepted']++;
                    continue;
                }

                $result['failed']++;
                Log::error('ZKTeco punch failed', [
                    'sn'    => $device->serial_number,
                    'line'  => $line,
                    'error' => $e->getMessage(),
                ]);
                ErrorNotifier::report($e);
            }
        }

        if ($result['stored']) {
            $device->forceFill(['last_punch_at' => now()])->save();
        }

        return $result;
    }

    /**
     * ZKTeco tab-separated format: PIN \t Timestamp \t Status \t VerifyMode \t WorkCode [\t ...]
     *
     * @return array{pin: string, time: Carbon, status: string, verify: string, work_code: string}|null
     */
    public function parse(string $line): ?array
    {
        $cols = array_map('trim', explode("\t", $line));

        $pin = $cols[0] ?? '';
        $time = $cols[1] ?? '';

        // PIN "0" is valid, so compare with '' rather than using a falsy check
        if ($pin === '' || $time === '' || mb_strlen($pin) > 191) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d H:i:s', $time);
        } catch (Throwable) {
            return null;
        }

        // createFromFormat accepts overflow such as 2026-13-45; reject anything that doesn't round-trip
        if (! $parsed || $parsed->format('Y-m-d H:i:s') !== $time) {
            return null;
        }

        return [
            'pin'       => $pin,
            'time'      => $parsed,
            'status'    => ($cols[2] ?? '') !== '' ? $cols[2] : '0',  // 0 = Check-In, 1 = Check-Out
            'verify'    => ($cols[3] ?? '') !== '' ? $cols[3] : '15', // 1 = Fingerprint, 15 = Face
            'work_code' => $cols[4] ?? '',
        ];
    }

    private function userIdForPin(string $pin): ?int
    {
        if (array_key_exists($pin, $this->userIds)) {
            return $this->userIds[$pin];
        }

        return $this->userIds[$pin] = User::where(config('zkteco.user_mapping.user_id_field', 'device_user_id'), $pin)
            ->orWhere(config('zkteco.user_mapping.alternative_field', 'employee_id'), $pin)
            ->value('id');
    }

    private function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\UniqueConstraintViolationException;
    }
}
