<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saves the user records a device sends: PIN, name, card number and privilege.
 *
 * Two formats arrive, depending on firmware:
 *   cdata?table=OPERLOG (or USERINFO):  USER PIN=1\tName=John Doe\tPri=0\tPasswd=\tCard=[0012345678]\tGrp=1 ...
 *   querydata?tablename=user:           user uid=1\tcardno=12345678\tpin=1\tpassword=\tgroup=1\tname=John Doe\tprivilege=0 ...
 *
 * Every other line (OPLOG operation entries, FP / FACE / BIODATA templates) is skipped and never stored.
 * Passwords are never read.
 */
class AdmsUserRecorder
{
    /**
     * @return int number of user records saved
     */
    public function record(string $deviceSn, string $body): int
    {
        $saved = 0;

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
            $data = $this->parse($line);
            if ($data === null) {
                continue;
            }

            try {
                Employee::syncFromDevice($data, $deviceSn);
                $saved++;
            } catch (Throwable $e) {
                Log::error('ZKTeco user record failed', ['sn' => $deviceSn, 'pin' => $data['pin'], 'error' => $e->getMessage()]);
            }
        }

        if ($saved > 0) {
            Log::info('ZKTeco users synced', ['sn' => $deviceSn, 'count' => $saved]);
        }

        return $saved;
    }

    /**
     * @return array{pin: string, name: ?string, card_number: ?string, privilege: ?int}|null  null if not a user record
     */
    public function parse(string $line): ?array
    {
        $line = trim($line);

        // "USER PIN=1..." / "user uid=1..." / a bare "PIN=1\tName=..." line
        if (preg_match('/^user\s+/i', $line)) {
            $line = preg_replace('/^user\s+/i', '', $line);
        } elseif (! preg_match('/^pin=/i', $line)) {
            return null;
        }

        $fields = [];
        foreach (explode("\t", $line) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[strtolower(trim($key))] = trim($value);
        }

        $pin = $fields['pin'] ?? '';
        if ($pin === '' || mb_strlen($pin) > 191) {
            return null;
        }

        $privilege = $fields['pri'] ?? $fields['privilege'] ?? null;

        return [
            'pin'         => $pin,
            'name'        => $this->cleanName($fields['name'] ?? ''),
            'card_number' => $this->cleanCard($fields['card'] ?? $fields['cardno'] ?? ''),
            'privilege'   => is_numeric($privilege) ? (int) $privilege : null,
        ];
    }

    private function cleanName(string $name): ?string
    {
        $name = str_replace("\0", '', $name);

        // Older firmware may send names in a legacy encoding
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }

        $name = trim($name);

        return $name === '' ? null : mb_substr($name, 0, 191);
    }

    /**
     * "[0012345678]", "0012345678" -> "12345678"; "", "0", "[0000000000]" -> null.
     */
    private function cleanCard(string $card): ?string
    {
        $card = ltrim(trim($card, "[] \t"), '0');

        return $card === '' ? null : mb_substr($card, 0, 191);
    }
}
