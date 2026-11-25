<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person enrolled on the devices, identified by their PIN.
 */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'pin',
        'name',
        'name_from_device',
        'card_number',
        'privilege',
        'department',
        'designation',
        'phone',
        'email',
        'notes',
        'device_sn',
        'synced_at',
    ];

    protected $casts = [
        'name_from_device' => 'boolean',
        'privilege'        => 'integer',
        'synced_at'        => 'datetime',
    ];

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'device_user_id', 'pin');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: "PIN {$this->pin}";
    }

    public function getInitialsAttribute(): string
    {
        if (! $this->name) {
            return '#';
        }

        $words = preg_split('/\s+/u', trim($this->name));

        return mb_strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr(end($words), 0, 1) : ''));
    }

    public function isDeviceAdmin(): bool
    {
        return $this->privilege === 14;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('pin', $term)
            ->orWhere('name', 'like', "%{$term}%")
            ->orWhere('department', 'like', "%{$term}%")
            ->orWhere('card_number', $term));
    }

    /**
     * Save details the device sent. A name edited on the dashboard is kept.
     *
     * @param  array{pin: string, name?: ?string, card_number?: ?string, privilege?: ?int}  $data
     */
    public static function syncFromDevice(array $data, string $deviceSn): self
    {
        $employee = static::firstOrNew(['pin' => $data['pin']]);

        if (($employee->name_from_device ?? true) && ! empty($data['name'])) {
            $employee->name = $data['name'];
            $employee->name_from_device = true;
        }

        if (array_key_exists('card_number', $data)) {
            $employee->card_number = $data['card_number'];
        }

        if (array_key_exists('privilege', $data)) {
            $employee->privilege = $data['privilege'];
        }

        $employee->device_sn = $deviceSn;
        $employee->synced_at = now();
        $employee->save();

        return $employee;
    }
}
