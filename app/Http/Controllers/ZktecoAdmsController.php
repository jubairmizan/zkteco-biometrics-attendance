<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Throwable;

class ZktecoAdmsController extends Controller
{
    /** Telegram rejects messages over 4096 characters; keep a margin for the "more" line. */
    private const TELEGRAM_MAX_LENGTH = 3900;

    private const STATUS_LABELS = [
        '0' => 'Check-In',
        '1' => 'Check-Out',
        '2' => 'Break-Out',
        '3' => 'Break-In',
        '4' => 'OT-In',
        '5' => 'OT-Out',
    ];

    private const VERIFY_LABELS = [
        '0'  => 'Password',
        '1'  => 'Fingerprint',
        '15' => 'Face',
    ];

    public function cdata(Request $request)
    {
        $deviceSn = $this->deviceSn($request);

        // 1. Handshake when device boots or connects: the device reads its upload settings from this reply
        if ($request->isMethod('get')) {
            Log::info('ZKTeco handshake', ['sn' => $deviceSn, 'ip' => $request->ip()]);

            return $this->plain(implode("\n", [
                "GET OPTION FROM: {$deviceSn}",
                'ATTLOGStamp=None',   // ask for any punches the server hasn't received yet
                'OPERLOGStamp=9999',  // skip operation logs
                'ATTPHOTOStamp=9999', // skip punch photos
                'ErrorDelay=60',      // seconds before retrying after a failed connection
                'Delay=30',           // seconds between getrequest polls
                'TransTimes=00:00;14:05',
                'TransInterval=1',
                'TransFlag=TransData AttLog',
                'Realtime=1',         // push each punch as soon as it happens
                'Encrypt=0',
            ]) . "\n");
        }

        // 2. Receive real-time attendance punches
        if ($request->query('table') !== 'ATTLOG') {
            // OPERLOG, ATTPHOTO, BIODATA, ...: acknowledge so the device doesn't resend them
            return $this->plain("OK\n");
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($request->getContent()));
        $punches = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // ZKTeco tab-separated format: PIN \t Timestamp \t Status \t VerifyMode \t WorkCode
            $cols = array_map('trim', explode("\t", $line));

            $employeePin = $cols[0] ?? '';
            $punchedAt   = $cols[1] ?? '';
            $status      = ($cols[2] ?? '') !== '' ? $cols[2] : '0';  // 0 = Check-In, 1 = Check-Out
            $verifyMode  = ($cols[3] ?? '') !== '' ? $cols[3] : '15'; // 1 = Fingerprint, 15 = Face

            // PIN "0" is valid, so compare with '' rather than using a falsy check
            if ($employeePin === '' || $punchedAt === '') {
                // Malformed line: report it to Telegram, keep processing the rest of the batch
                ErrorNotifier::report(new \UnexpectedValueException(
                    "ZKTeco ADMS: malformed ATTLOG line from device {$deviceSn}: " . json_encode($line)
                ));
                continue;
            }

            try {
                // Log to storage/logs/laravel.log for instant verification
                Log::info('ZKTeco Punch', [
                    'sn'          => $deviceSn,
                    'pin'         => $employeePin,
                    'punched_at'  => $punchedAt,
                    'status'      => $status,
                    'verify_mode' => $verifyMode,
                ]);

                $punches[] = compact('employeePin', 'punchedAt', 'status', 'verifyMode');

                // Example DB insert (replace table/columns with yours):
                /*
                DB::table('attendances')->insertOrIgnore([
                    'employee_pin' => $employeePin,
                    'punched_at'   => $punchedAt,
                    'status'       => $status,
                    'device_sn'    => $deviceSn,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                */
            } catch (Throwable $e) {
                // One failing punch must not drop the whole batch; send it to Telegram and move on
                Log::error('ZKTeco punch failed', [
                    'sn'    => $deviceSn,
                    'line'  => $line,
                    'error' => $e->getMessage(),
                ]);
                ErrorNotifier::report($e);
            }
        }

        $this->sendPunchesToTelegram($deviceSn, $punches);

        return $this->plain('OK: ' . count($punches) . "\n");
    }

    /**
     * The device polls this for pending commands. Nothing is queued, so it is told there's nothing to do.
     */
    public function getrequest(Request $request)
    {
        return $this->plain("OK\n");
    }

    /**
     * The device reports the result of a command here.
     */
    public function devicecmd(Request $request)
    {
        return $this->plain("OK\n");
    }

    /**
     * Send one Telegram message per upload, listing every punch in it.
     * A Telegram failure is only logged: it must never affect the device's response.
     */
    private function sendPunchesToTelegram(string $deviceSn, array $punches): void
    {
        $token  = config('error-notifier.drivers.telegram.bot_token');
        $chatId = config('error-notifier.drivers.telegram.chat_id');

        if (empty($punches) || ! $token || ! $chatId) {
            return;
        }

        $text = "ZKTeco Punch (device {$deviceSn})";
        $sent = 0;

        foreach ($punches as $p) {
            $type   = self::STATUS_LABELS[$p['status']] ?? "Status {$p['status']}";
            $method = self::VERIFY_LABELS[$p['verifyMode']] ?? "Mode {$p['verifyMode']}";
            $row    = "\n• PIN {$p['employeePin']} | {$p['punchedAt']} | {$type} | {$method}";

            // Stop on a whole line, and say how many were left out, instead of cutting mid-line
            if (mb_strlen($text . $row) > self::TELEGRAM_MAX_LENGTH) {
                break;
            }

            $text .= $row;
            $sent++;
        }

        if ($sent < count($punches)) {
            $text .= "\n… and " . (count($punches) - $sent) . ' more (see laravel.log)';
        }

        try {
            Http::connectTimeout(3)
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text'    => $text,
                ])
                ->throw();
        } catch (Throwable $e) {
            // Don't include the exception message: it can contain the bot token in the URL
            Log::warning('ZKTeco punch Telegram message failed', [
                'sn'     => $deviceSn,
                'error'  => get_class($e),
                'status' => $e->getCode(),
            ]);
        }
    }

    /**
     * The serial number from the query string. A non-string value (e.g. ?SN[]=x) is treated as unknown.
     */
    private function deviceSn(Request $request): string
    {
        $sn = $request->query('SN');

        return is_string($sn) && $sn !== '' ? $sn : 'unknown';
    }

    private function plain(string $body)
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
