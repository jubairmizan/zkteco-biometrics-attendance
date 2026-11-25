@extends('layouts.app')

@section('title', 'Attendance')

@php
    $typeColors = [
        'check_in' => 'bg-green-100 text-green-800',
        'check_out' => 'bg-red-100 text-red-800',
        'break_out' => 'bg-yellow-100 text-yellow-800',
        'break_in' => 'bg-blue-100 text-blue-800',
        'overtime_in' => 'bg-purple-100 text-purple-800',
        'overtime_out' => 'bg-pink-100 text-pink-800',
    ];
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

@section('content')
<div x-data="attendanceApp({{ $latestId }})" class="space-y-6">

            <!-- New punches banner -->
            <div x-show="newCount > 0" x-cloak
                class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-3 text-sm flex justify-between items-center">
                <span><i class="fas fa-bell mr-2"></i><span x-text="newCount"></span> new punch(es) received.</span>
                <button @click="window.location.reload()" class="font-semibold hover:underline">Show</button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-lg shadow p-6 flex items-center">
                    <div class="p-3 bg-yellow-100 rounded-md"><i class="fas fa-calendar-day text-xl text-yellow-600"></i></div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Punches today</p>
                        <p class="text-2xl font-semibold text-gray-900" x-text="today">{{ $stats['today'] }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6 flex items-center">
                    <div class="p-3 bg-green-100 rounded-md"><i class="fas fa-users text-xl text-green-600"></i></div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Employees punched today</p>
                        <p class="text-2xl font-semibold text-gray-900">{{ $stats['today_employees'] }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6 flex items-center">
                    <div class="p-3 bg-blue-100 rounded-md"><i class="fas fa-list text-xl text-blue-600"></i></div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">All punches</p>
                        <p class="text-2xl font-semibold text-gray-900">{{ number_format($stats['total']) }}</p>
                    </div>
                </div>
            </div>

            <!-- Devices -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Devices</h2>
                </div>
                @if ($devices->isEmpty())
                    <p class="px-6 py-4 text-sm text-gray-500">
                        No device has connected yet. On the device, set Cloud Server Settings → Server Mode ADMS,
                        and the server address and port of this app. It appears here on its first connection.
                    </p>
                @else
                    <ul class="divide-y divide-gray-200">
                        @foreach ($devices as $device)
                            <li class="px-6 py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                                <div class="flex items-center gap-3">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full"
                                        :class="deviceOnline(@js($device->serial_number), {{ $device->isOnline() ? 'true' : 'false' }}) ? 'bg-green-500' : 'bg-gray-400'"></span>
                                    <span class="font-medium text-gray-900">{{ $device->name ?: $device->serial_number }}</span>
                                    @if ($device->name)
                                        <span class="text-gray-500">{{ $device->serial_number }}</span>
                                    @endif
                                    <span class="text-gray-500">{{ $device->ip_address }}</span>
                                </div>
                                <div class="text-gray-500">
                                    Last contact:
                                    <span x-text="deviceLastSeen(@js($device->serial_number), @js($device->last_seen_at?->diffForHumans() ?? 'never'))"></span>
                                    · Last punch: {{ $device->last_punch_at?->diffForHumans() ?? 'none yet' }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <!-- Filters -->
            <form method="GET" action="{{ route('attendance.index') }}" class="bg-white rounded-lg shadow p-6">
                <div class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="start_date">From</label>
                        <input type="date" id="start_date" name="start_date" value="{{ $filters['start_date'] ?? '' }}"
                            class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="end_date">To</label>
                        <input type="date" id="end_date" name="end_date" value="{{ $filters['end_date'] ?? '' }}"
                            class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="search">PIN or name</label>
                        <input type="text" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="e.g. 1001"
                            class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="punch_type">Type</label>
                        <select id="punch_type" name="punch_type" class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm">
                            <option value="">All</option>
                            @foreach ($punchTypes as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['punch_type'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="device_sn">Device</label>
                        <select id="device_sn" name="device_sn" class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm">
                            <option value="">All</option>
                            @foreach ($devices as $device)
                                <option value="{{ $device->serial_number }}" @selected(($filters['device_sn'] ?? '') === $device->serial_number)>
                                    {{ $device->name ?: $device->serial_number }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">
                            <i class="fas fa-filter mr-1"></i>Filter
                        </button>
                        @if ($hasFilters)
                            <a href="{{ route('attendance.index') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50" title="Clear filters">
                                <i class="fas fa-times"></i>
                            </a>
                        @endif
                    </div>
                </div>
            </form>

            <!-- Attendance list -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Attendance
                        <span class="text-sm font-normal text-gray-500">({{ number_format($logs->total()) }} {{ $hasFilters ? 'matching' : 'total' }})</span>
                    </h2>
                    <a href="{{ route('attendance.export', request()->query()) }}"
                        class="text-sm px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                        <i class="fas fa-download mr-1 text-purple-600"></i>Export CSV
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach (['Employee', 'PIN', 'Punch time', 'Type', 'Verification', 'Device'] as $heading)
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($logs as $log)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                                        @php($personUrl = $log->employee ? route('employees.show', $log->employee) : null)
                                        <div class="flex items-center gap-3">
                                            <div class="h-9 w-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-semibold flex-shrink-0">
                                                {{ $log->employee?->initials ?? '#' }}
                                            </div>
                                            <div>
                                                @if ($log->person_name)
                                                    @if ($personUrl)
                                                        <a href="{{ $personUrl }}" class="font-medium text-gray-900 hover:text-blue-700">{{ $log->person_name }}</a>
                                                    @else
                                                        <span class="font-medium text-gray-900">{{ $log->person_name }}</span>
                                                    @endif
                                                @elseif ($personUrl)
                                                    <a href="{{ $personUrl }}" class="text-gray-400 hover:text-blue-700" title="Add a name for this PIN">No name yet</a>
                                                @else
                                                    <span class="text-gray-400">No name yet</span>
                                                @endif
                                                @if ($log->employee?->department || $log->employee?->designation)
                                                    <div class="text-xs text-gray-500">
                                                        {{ collect([$log->employee->designation, $log->employee->department])->filter()->implode(' · ') }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900">{{ $log->device_user_id }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900">{{ $log->punch_time->format('M d, Y H:i:s') }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $typeColors[$log->punch_type] ?? 'bg-gray-100 text-gray-800' }}">
                                            {{ $log->punch_type_label }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {{ ucfirst(str_replace('_', ' ', $log->verification_type ?? 'unknown')) }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500">{{ $log->device_sn ?? $log->device_ip }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        @if ($hasFilters)
                                            No punches match these filters.
                                        @else
                                            No punches yet. They appear here as soon as the device sends them.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($logs->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
</div>
@endsection

@push('scripts')
    <script>
        function attendanceApp(latestId) {
            return {
                latestId,
                newCount: 0,
                today: {{ $stats['today'] }},
                devices: {},

                init() {
                    setInterval(() => this.poll(), 15000);
                },

                async poll() {
                    try {
                        const response = await fetch(@js(route('attendance.latest')), {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (response.status === 401) {
                            window.location.reload(); // session expired: go to login
                            return;
                        }
                        if (!response.ok) return;

                        const data = await response.json();
                        this.today = data.today;
                        this.devices = Object.fromEntries(data.devices.map(d => [d.serial_number, d]));

                        if (data.latest_id > this.latestId) {
                            this.newCount = data.latest_id - this.latestId;
                        }
                    } catch (e) {
                        // Network blip: try again on the next tick
                    }
                },

                deviceOnline(sn, initial) {
                    return this.devices[sn] ? this.devices[sn].online : initial;
                },

                deviceLastSeen(sn, initial) {
                    return this.devices[sn]?.last_seen ?? initial;
                },
            }
        }
    </script>
@endpush
