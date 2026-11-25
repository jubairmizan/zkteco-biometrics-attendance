<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = $validated['search'] ?? null;

        $employees = Employee::search($search)
            ->withCount(['attendanceLogs as punches_today' => fn ($q) => $q->whereBetween('punch_time', [now()->startOfDay(), now()->endOfDay()])])
            ->withMax('attendanceLogs as last_punch_at', 'punch_time')
            ->orderByRaw('name IS NULL') // named people first
            ->orderBy('name')
            ->orderBy('pin')
            ->paginate(25)
            ->withQueryString();

        return view('employees.index', [
            'employees'      => $employees,
            'search'         => $search,
            'devices'        => Device::orderBy('serial_number')->get(),
            // A command the device never answers (unsupported firmware) stops counting after 10 minutes
            'pendingFetches' => DeviceCommand::where('command', DeviceCommand::QUERY_USERS)
                ->where(fn ($q) => $q->where('status', 'pending')
                    ->orWhere(fn ($q) => $q->where('status', 'sent')->where('sent_at', '>', now()->subMinutes(10))))
                ->count(),
            'unnamed'        => Employee::whereNull('name')->count(),
        ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = isset($validated['month']) ? Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth() : now()->startOfMonth();

        $monthLogs = $employee->attendanceLogs()
            ->whereBetween('punch_time', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->orderBy('punch_time')
            ->get();

        $days = $this->dailySummary($monthLogs);

        return view('employees.show', [
            'employee'    => $employee,
            'month'       => $month,
            'days'        => $days,
            'monthTotals' => [
                'days_present' => $days->count(),
                'punches'      => $monthLogs->count(),
                'minutes'      => $days->sum('minutes'),
            ],
            'recent'      => $employee->attendanceLogs()->orderByDesc('punch_time')->paginate(15, ['*'], 'page')->withQueryString(),
            'lastPunch'   => $employee->attendanceLogs()->max('punch_time'),
            'firstPunch'  => $employee->attendanceLogs()->min('punch_time'),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['nullable', 'string', 'max:191'],
            'department'  => ['nullable', 'string', 'max:191'],
            'designation' => ['nullable', 'string', 'max:191'],
            'phone'       => ['nullable', 'string', 'max:50'],
            'email'       => ['nullable', 'email', 'max:191'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        // A name typed here wins over the device's from now on; clearing it hands the name back to the device
        if (array_key_exists('name', $data) && $data['name'] !== $employee->name) {
            $data['name_from_device'] = $data['name'] === null;
        }

        $employee->update($data);

        return redirect()->route('employees.show', $employee)->with('success', 'Details saved.');
    }

    /**
     * Ask every device for its user list. Each device picks the request up on its next poll (about 30 s).
     */
    public function fetchFromDevices(): RedirectResponse
    {
        $devices = Device::pluck('serial_number');

        if ($devices->isEmpty()) {
            return back()->with('error', 'No device has connected yet.');
        }

        $devices->each(fn (string $sn) => DeviceCommand::queue($sn, DeviceCommand::QUERY_USERS));

        return back()->with('success', 'Asked ' . $devices->count() . ' device(s) for their user list. Names appear within a minute while the device is online.');
    }

    /**
     * One row per day: first punch, last punch, time between them.
     */
    private function dailySummary(Collection $logs): Collection
    {
        return $logs
            ->groupBy(fn (AttendanceLog $log) => $log->punch_time->toDateString())
            ->map(function (Collection $dayLogs, string $date) {
                $first = $dayLogs->first()->punch_time;
                $last = $dayLogs->last()->punch_time;
                $minutes = $dayLogs->count() > 1 ? (int) $first->diffInMinutes($last) : 0;

                return [
                    'date'    => Carbon::parse($date),
                    'first'   => $first,
                    'last'    => $dayLogs->count() > 1 ? $last : null,
                    'minutes' => $minutes,
                    'punches' => $dayLogs,
                ];
            })
            ->sortKeysDesc()
            ->values();
    }
}
