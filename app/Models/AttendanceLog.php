<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    /** Device status code => stored punch_type (config/zkteco.php punch_types). */
    public const PUNCH_TYPES = [
        'check_in'     => 'Check-In',
        'check_out'    => 'Check-Out',
        'break_out'    => 'Break-Out',
        'break_in'     => 'Break-In',
        'overtime_in'  => 'OT-In',
        'overtime_out' => 'OT-Out',
    ];

    public const ATTENDANCE_STATUSES = [
        'late'        => 'Late',
        'early_leave' => 'Early leave',
    ];

    protected $fillable = [
        'user_id',
        'device_user_id',
        'device_ip',
        'device_sn',
        'punch_time',
        'punch_type',
        'device_status',
        'attendance_status',
        'verification_type',
        'work_code',
        'is_processed',
    ];

    protected $casts = [
        'punch_time' => 'datetime',
        'is_processed' => 'boolean',
        'device_status' => 'integer',
    ];

    /**
     * Get the user that owns the attendance log.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The person behind the PIN (name, department, ...), from the employees table.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'device_user_id', 'pin');
    }

    /**
     * Best name we have: the employee record, then a linked login user.
     */
    public function getPersonNameAttribute(): ?string
    {
        return $this->employee?->name ?: $this->user?->name;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_sn', 'serial_number');
    }

    public function getAttendanceStatusLabelAttribute(): ?string
    {
        return $this->attendance_status ? (self::ATTENDANCE_STATUSES[$this->attendance_status] ?? $this->attendance_status) : null;
    }

    public function getPunchTypeLabelAttribute(): string
    {
        return self::PUNCH_TYPES[$this->punch_type] ?? ucfirst(str_replace('_', ' ', (string) $this->punch_type));
    }

    /**
     * Scope to get unprocessed logs.
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Scope to get logs by device.
     */
    public function scopeByDevice($query, $deviceIp)
    {
        return $query->where('device_ip', $deviceIp);
    }

    /**
     * Apply the dashboard / export / JSON filters. Empty values are ignored.
     *
     * @param  array{start_date?: ?string, end_date?: ?string, search?: ?string, punch_type?: ?string, attendance_status?: ?string, device_sn?: ?string, device_ip?: ?string, user_id?: mixed}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        if (isset($filters['start_date'])) {
            $query->where('punch_time', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }

        if (isset($filters['end_date'])) {
            $query->where('punch_time', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }

        if (isset($filters['search'])) {
            $term = $filters['search'];
            $query->where(function (Builder $q) use ($term) {
                $q->where('device_user_id', $term)
                    ->orWhereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$term}%")
                        ->orWhere('department', 'like', "%{$term}%"))
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$term}%")
                        ->orWhere('employee_id', $term));
            });
        }

        foreach (['punch_type', 'attendance_status', 'device_sn', 'device_ip', 'user_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }
}
