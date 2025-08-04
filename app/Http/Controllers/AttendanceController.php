<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\ZKTecoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    private $zkTecoService;

    public function __construct()
    {
        $this->zkTecoService = new ZKTecoService();
    }

    /**
     * Display attendance logs dashboard
     */
    public function index(): View
    {
        $logs = AttendanceLog::with('user')
            ->orderBy('punch_time', 'desc')
            ->paginate(20);

        return view('attendance.index', compact('logs'));
    }

    /**
     * Test device connection
     */
    public function testConnection(): JsonResponse
    {
        $result = $this->zkTecoService->testConnection();
        return response()->json($result);
    }

    /**
     * Get device information
     */
    public function getDeviceInfo(): JsonResponse
    {
        $info = $this->zkTecoService->getDeviceInfo();
        return response()->json($info);
    }

    /**
     * Sync attendance logs from device
     */
    public function syncLogs(): JsonResponse
    {
        $result = $this->zkTecoService->syncAttendanceLogs();
        return response()->json([
            'success' => true,
            'message' => "Synced {$result['synced_count']} out of {$result['total_logs']} logs",
            'data' => $result
        ]);
    }

    /**
     * Get device users
     */
    public function getDeviceUsers(): JsonResponse
    {
        $users = $this->zkTecoService->getDeviceUsers();
        return response()->json($users);
    }

    /**
     * Sync device users to database
     */
    public function syncDeviceUsers(): JsonResponse
    {
        $result = $this->zkTecoService->syncDeviceUsers();
        return response()->json([
            'success' => true,
            'message' => 'Device users synchronized successfully',
            'data' => $result
        ]);
    }

    /**
     * Get attendance logs with filters
     */
    public function getLogs(Request $request): JsonResponse
    {
        $query = AttendanceLog::with('user');

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('punch_time', '>=', Carbon::parse($request->start_date));
        }

        if ($request->has('end_date')) {
            $query->where('punch_time', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by device
        if ($request->has('device_ip')) {
            $query->where('device_ip', $request->device_ip);
        }

        // Filter by punch type
        if ($request->has('punch_type')) {
            $query->where('punch_type', $request->punch_type);
        }

        $logs = $query->orderBy('punch_time', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($logs);
    }

    /**
     * Get attendance summary for a user
     */
    public function getUserSummary(Request $request, $userId): JsonResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth());

        $user = User::findOrFail($userId);
        
        $logs = AttendanceLog::where('user_id', $userId)
            ->whereBetween('punch_time', [$startDate, $endDate])
            ->orderBy('punch_time')
            ->get();

        $summary = [
            'user' => $user,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'total_days' => $logs->groupBy(function($log) {
                return $log->punch_time->format('Y-m-d');
            })->count(),
            'total_check_ins' => $logs->where('punch_type', 'check_in')->count(),
            'total_check_outs' => $logs->where('punch_type', 'check_out')->count(),
            'logs' => $logs->groupBy(function($log) {
                return $log->punch_time->format('Y-m-d');
            })
        ];

        return response()->json($summary);
    }

    /**
     * Mark attendance logs as processed
     */
    public function markAsProcessed(Request $request): JsonResponse
    {
        $logIds = $request->input('log_ids', []);
        
        AttendanceLog::whereIn('id', $logIds)
            ->update(['is_processed' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Logs marked as processed',
            'processed_count' => count($logIds)
        ]);
    }

    /**
     * Clear attendance logs from device
     */
    public function clearDeviceLogs(): JsonResponse
    {
        $result = $this->zkTecoService->clearAttendanceLogs();
        
        return response()->json([
            'success' => $result,
            'message' => $result ? 'Device logs cleared successfully' : 'Failed to clear device logs'
        ]);
    }

    /**
     * Real-time sync endpoint (can be called via AJAX polling)
     */
    public function realtimeSync(): JsonResponse
    {
        $result = $this->zkTecoService->syncAttendanceLogs();
        
        return response()->json([
            'success' => true,
            'new_logs' => $result['synced_count'],
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }

    /**
     * Export attendance logs to CSV
     */
    public function exportLogs(Request $request)
    {
        $query = AttendanceLog::with('user');

        // Apply filters
        if ($request->has('start_date')) {
            $query->where('punch_time', '>=', Carbon::parse($request->start_date));
        }

        if ($request->has('end_date')) {
            $query->where('punch_time', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        $logs = $query->orderBy('punch_time')->get();

        $filename = 'attendance_logs_' . Carbon::now()->format('Y_m_d_H_i_s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($logs) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'ID',
                'User Name',
                'Device User ID',
                'Device IP',
                'Punch Time',
                'Punch Type',
                'Verification Type',
                'Work Code',
                'Is Processed'
            ]);

            // CSV data
            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->user ? $log->user->name : 'Unknown',
                    $log->device_user_id,
                    $log->device_ip,
                    $log->punch_time->format('Y-m-d H:i:s'),
                    $log->punch_type,
                    $log->verification_type,
                    $log->work_code,
                    $log->is_processed ? 'Yes' : 'No'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
