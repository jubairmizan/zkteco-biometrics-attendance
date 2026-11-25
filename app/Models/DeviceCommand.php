<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A command waiting for a device: sent on its next /iclock/getrequest poll as "C:<id>:<command>".
 */
class DeviceCommand extends Model
{
    /** Ask the device for every enrolled user (PIN, name, card, privilege). */
    public const QUERY_USERS = 'DATA QUERY USERINFO';

    protected $fillable = ['device_sn', 'command', 'status', 'return_code', 'sent_at', 'completed_at'];

    protected $casts = [
        'return_code'  => 'integer',
        'sent_at'      => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Queue a command unless the same one is already waiting.
     */
    public static function queue(string $deviceSn, string $command): self
    {
        return static::firstOrCreate(
            ['device_sn' => $deviceSn, 'command' => $command, 'status' => 'pending']
        );
    }
}
