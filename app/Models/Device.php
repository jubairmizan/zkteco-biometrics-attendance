<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** A device polls every ~30 s (Delay=30 in the handshake); after this long without contact it's shown offline. */
    public const ONLINE_WINDOW_MINUTES = 3;

    protected $fillable = [
        'serial_number',
        'name',
        'ip_address',
        'push_version',
        'last_seen_at',
        'last_punch_at',
    ];

    protected $casts = [
        'last_seen_at'  => 'datetime',
        'last_punch_at' => 'datetime',
    ];

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'device_sn', 'serial_number');
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }

    /**
     * Record that the device just contacted the server.
     */
    public static function touchFromRequest(string $serialNumber, ?string $ip, ?string $pushVersion = null): self
    {
        $device = static::firstOrNew(['serial_number' => $serialNumber]);
        $device->ip_address   = $ip ?? $device->ip_address;
        $device->push_version = $pushVersion ?? $device->push_version;
        $device->last_seen_at = now();
        $device->save();

        if ($device->wasRecentlyCreated) {
            // First contact: ask the device for its user list so punches show names straight away
            DeviceCommand::queue($device->serial_number, DeviceCommand::QUERY_USERS);
        }

        return $device;
    }
}
