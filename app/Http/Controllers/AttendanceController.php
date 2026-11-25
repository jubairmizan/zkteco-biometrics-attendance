<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    /**
     * Dashboard: devices, today's numbers, and the filterable punch list.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $logs = AttendanceLog::with(['user', 'employee'])
            ->filter($filters)
            ->orderByDesc('punch_time')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $today = AttendanceLog::whereBetween('punch_time', [now()->startOfDay(), now()->endOfDay()]);

        $stats = [
            'total'           => AttendanceLog::count(),
            'today'           => (clone $today)->count(),
            'today_employees' => (clone $today)->distinct()->count('device_user_id'),
            'today_late'      => (clone $today)->where('attendance_status', 'late')->distinct()->count('device_user_id'),
        ];

        return view('attendance.index', [
            'logs'       => $logs,
            'filters'    => $filters,
            'stats'      => $stats,
            'devices'    => Device::orderByDesc('last_seen_at')->get(),
            'punchTypes' => AttendanceLog::PUNCH_TYPES,
            'statuses'   => AttendanceLog::ATTENDANCE_STATUSES,
            'latestId'   => (int) AttendanceLog::max('id'),
        ]);
    }

    /**
     * Polled by the dashboard to notice new punches without reloading.
     */
    public function latest(): JsonResponse
    {
        return response()->json([
            'latest_id' => (int) AttendanceLog::max('id'),
            'today'     => AttendanceLog::whereBetween('punch_time', [now()->startOfDay(), now()->endOfDay()])->count(),
            'devices'   => Device::orderByDesc('last_seen_at')->get()->map(fn (Device $d) => [
                'serial_number' => $d->serial_number,
                'online'        => $d->isOnline(),
                'last_seen'     => $d->last_seen_at?->diffForHumans(),
            ]),
        ]);
    }

    /**
     * Get attendance logs with filters (JSON).
     */
    public function getLogs(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);

        $logs = AttendanceLog::with(['user', 'employee'])
            ->filter($this->filters($request))
            ->orderByDesc('punch_time')
            ->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Get attendance summary for a user
     */
    public function getUserSummary(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        // The whole end day is included (a plain date would stop at 00:00:00)
        $startDate = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : now()->startOfMonth();
        $endDate = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : now()->endOfMonth();

        $logs = AttendanceLog::where('user_id', $user->id)
            ->whereBetween('punch_time', [$startDate, $endDate])
            ->orderBy('punch_time')
            ->get();

        $byDay = $logs->groupBy(fn (AttendanceLog $log) => $log->punch_time->format('Y-m-d'));

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'device_user_id', 'employee_id']),
            'period' => [
                'start' => $startDate->toDateString(),
                'end'   => $endDate->toDateString(),
            ],
            'total_days'       => $byDay->count(),
            'total_check_ins'  => $logs->where('punch_type', 'check_in')->count(),
            'total_check_outs' => $logs->where('punch_type', 'check_out')->count(),
            'logs'             => $byDay,
        ]);
    }

    /**
     * Mark attendance logs as processed
     */
    public function markAsProcessed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'log_ids'   => ['required', 'array', 'max:1000'],
            'log_ids.*' => ['integer'],
        ]);

        $count = AttendanceLog::whereIn('id', $validated['log_ids'])->update(['is_processed' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Logs marked as processed',
            'processed_count' => $count,
        ]);
    }

    /**
     * Export the filtered attendance logs to CSV.
     */
    public function exportLogs(Request $request)
    {
        $query = AttendanceLog::with(['user', 'employee'])
            ->filter($this->filters($request))
            ->orderBy('punch_time');

        $filename = 'attendance_logs_' . now()->format('Y_m_d_H_i_s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'ID', 'Employee', 'Department', 'PIN', 'Punch Time', 'Punch Type', 'Status', 'Verification', 'Device SN', 'Device IP', 'Processed',
            ]);

            // Chunked so a large export doesn't load every row into memory
            $query->chunk(1000, function ($logs) use ($file) {
                foreach ($logs as $log) {
                    fputcsv($file, [
                        $log->id,
                        $this->csvSafe($log->person_name ?? ''),
                        $this->csvSafe((string) $log->employee?->department),
                        $this->csvSafe($log->device_user_id),
                        $log->punch_time->format('Y-m-d H:i:s'),
                        $log->punch_type_label,
                        $log->attendance_status_label ?? 'On time',
                        $log->verification_type,
                        $this->csvSafe((string) $log->device_sn),
                        $log->device_ip,
                        $log->is_processed ? 'Yes' : 'No',
                    ]);
                }
            });

            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Validated filter values from the query string.
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date'],
            'search'     => ['nullable', 'string', 'max:100'],
            'punch_type' => ['nullable', Rule::in(array_keys(AttendanceLog::PUNCH_TYPES))],
            'attendance_status' => ['nullable', Rule::in(array_keys(AttendanceLog::ATTENDANCE_STATUSES))],
            'device_sn'  => ['nullable', 'string', 'max:191'],
            'device_ip'  => ['nullable', 'string', 'max:45'],
            'user_id'    => ['nullable', 'integer'],
        ]);
    }

    /**
     * Stop a value from the device (PIN, name, serial) running as a formula when the CSV is opened in Excel.
     */
    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
