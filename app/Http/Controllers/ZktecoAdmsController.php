<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\AdmsPunchRecorder;
use App\Services\AdmsUserRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Throwable;

/**
 * ZKTeco ADMS (push) protocol: the device calls these endpoints; see routes/iclock.php.
 */
class ZktecoAdmsController extends Controller
{
    /** Telegram rejects messages over 4096 characters; keep a margin for the "more" line. */
    private const TELEGRAM_MAX_LENGTH = 3900;

    /** Upload tables that can carry user records (name, card, privilege). */
    private const USER_TABLES = ['OPERLOG', 'USERINFO', 'USER'];

    public function cdata(Request $request, AdmsPunchRecorder $recorder, AdmsUserRecorder $users)
    {
        $deviceSn = $this->deviceSn($request);
        $device = $this->touchDevice($request, $deviceSn);

        // 1. Handshake when device boots or connects: the device reads its upload settings from this reply
        if ($request->isMethod('get')) {
            Log::info('ZKTeco handshake', ['sn' => $deviceSn, 'ip' => $request->ip()]);

            return $this->plain(implode("\n", [
                "GET OPTION FROM: {$deviceSn}",
                'ATTLOGStamp=None',   // ask for any punches the server hasn't received yet
                'OPERLOGStamp=None',  // operation log: carries user records (names) when someone is enrolled or edited
                'ATTPHOTOStamp=9999', // skip punch photos
                'ErrorDelay=60',      // seconds before retrying after a failed connection
                'Delay=30',           // seconds between getrequest polls
                'TransTimes=00:00;14:05',
                'TransInterval=1',
                // Upload punches, the operation log and user details. Fingerprint / face templates are left out.
                'TransFlag=TransData AttLog OpLog EnrollUser ChgUser',
                'Realtime=1',         // push each punch as soon as it happens
                'Encrypt=0',
            ]) . "\n");
        }

        $table = strtoupper((string) (is_string($request->query('table')) ? $request->query('table') : ''));

        // 2. User records (names) inside the operation log
        if (in_array($table, self::USER_TABLES, true)) {
            $users->record($deviceSn, $request->getContent());

            return $this->plain("OK\n");
        }

        // 3. Receive attendance punches
        if ($table !== 'ATTLOG') {
            // ATTPHOTO, BIODATA, ...: acknowledge so the device doesn't resend them
            return $this->plain("OK\n");
        }

        if ($device === null) {
            // The database is unreachable: tell the device to keep the punches and try again later
            return $this->plain("ERROR\n", 500);
        }

        $result = $recorder->record($device, $request->ip(), $request->getContent());

        $this->sendPunchesToTelegram($deviceSn, $result['stored']);

        if ($result['failed'] > 0) {
            // Some punches weren't saved. A non-200 reply makes the device send the whole upload again;
            // punches already saved are skipped as duplicates, so nothing is stored twice.
            return $this->plain("ERROR: {$result['failed']} punch(es) not saved\n", 500);
        }

        return $this->plain('OK: ' . $result['accepted'] . "\n");
    }

    /**
     * The device polls this for pending commands. Each is sent once, as "C:<id>:<command>".
     */
    public function getrequest(Request $request)
    {
        $deviceSn = $this->deviceSn($request);
        $this->touchDevice($request, $deviceSn);

        try {
            $commands = DeviceCommand::where('device_sn', $deviceSn)
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit(10)
                ->get();

            if ($commands->isNotEmpty()) {
                DeviceCommand::whereIn('id', $commands->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);

                return $this->plain($commands->map(fn (DeviceCommand $c) => "C:{$c->id}:{$c->command}")->implode("\n") . "\n");
            }
        } catch (Throwable $e) {
            Log::error('ZKTeco command lookup failed', ['sn' => $deviceSn, 'error' => $e->getMessage()]);
        }

        return $this->plain("OK\n");
    }

    /**
     * The device reports command results here, one per line: ID=<id>&Return=<code>&CMD=<name>
     */
    public function devicecmd(Request $request)
    {
        $deviceSn = $this->deviceSn($request);
        $this->touchDevice($request, $deviceSn);

        foreach (preg_split('/\r\n|\r|\n/', trim($request->getContent())) as $line) {
            parse_str(trim($line), $result);

            if (! isset($result['ID']) || ! ctype_digit((string) $result['ID'])) {
                continue;
            }

            $code = isset($result['Return']) && is_numeric($result['Return']) ? (int) $result['Return'] : null;

            DeviceCommand::where('id', $result['ID'])
                ->where('device_sn', $deviceSn) // a device can only close its own commands
                ->update([
                    'status'       => $code !== null && $code < 0 ? 'failed' : 'done',
                    'return_code'  => $code,
                    'completed_at' => now(),
                ]);
        }

        return $this->plain("OK\n");
    }

    /**
     * Newer firmware answers "DATA QUERY USERINFO" here instead of in cdata:
     * POST /iclock/querydata?SN=...&type=tabledata&tablename=user&count=N
     */
    public function querydata(Request $request, AdmsUserRecorder $users)
    {
        $deviceSn = $this->deviceSn($request);
        $this->touchDevice($request, $deviceSn);

        $tableName = $request->query('tablename');
        $tableName = is_string($tableName) ? strtolower($tableName) : '';

        if ($tableName === 'user') {
            $saved = $users->record($deviceSn, $request->getContent());

            return $this->plain("user={$saved}\n");
        }

        // Other tables (fingerprint / face templates, photos): acknowledged, not stored
        return $this->plain(($tableName !== '' ? $tableName : 'OK') . "=0\n");
    }

    /**
     * Mark the device as seen. Never lets a database error break the reply to the device.
     */
    private function touchDevice(Request $request, string $deviceSn): ?Device
    {
        try {
            $pushVersion = $request->query('pushver');

            return Device::touchFromRequest($deviceSn, $request->ip(), is_string($pushVersion) ? $pushVersion : null);
        } catch (Throwable $e) {
            Log::error('ZKTeco device update failed', ['sn' => $deviceSn, 'error' => $e->getMessage()]);
            ErrorNotifier::report($e);

            return null;
        }
    }

    /**
     * Send one Telegram message per upload, listing every newly stored punch.
     * Punches the device re-sends are not repeated. A Telegram failure is only logged.
     *
     * @param  array<int, AttendanceLog>  $logs
     */
    private function sendPunchesToTelegram(string $deviceSn, array $logs): void
    {
        $token  = config('error-notifier.drivers.telegram.bot_token');
        $chatId = config('error-notifier.drivers.telegram.chat_id');

        if (empty($logs) || ! $token || ! $chatId) {
            return;
        }

        (new \Illuminate\Database\Eloquent\Collection($logs))->load(['user', 'employee']);

        $text = "ZKTeco Punch (device {$deviceSn})";
        $sent = 0;

        foreach ($logs as $log) {
            $name = $log->person_name;
            $who = $name ? "{$name} (PIN {$log->device_user_id})" : "PIN {$log->device_user_id}";
            $method = ucfirst(str_replace('_', ' ', (string) $log->verification_type));
            $row = "\n• {$who} | {$log->punch_time->format('Y-m-d H:i:s')} | {$log->punch_type_label} | {$method}";

            // Stop on a whole line, and say how many were left out, instead of cutting mid-line
            if (mb_strlen($text . $row) > self::TELEGRAM_MAX_LENGTH) {
                break;
            }

            $text .= $row;
            $sent++;
        }

        if ($sent < count($logs)) {
            $text .= "\n… and " . (count($logs) - $sent) . ' more (see the dashboard)';
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

        return is_string($sn) && $sn !== '' ? mb_substr($sn, 0, 191) : 'unknown';
    }

    private function plain(string $body, int $status = 200)
    {
        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}
