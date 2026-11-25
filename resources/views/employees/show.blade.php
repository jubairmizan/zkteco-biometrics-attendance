@extends('layouts.app')

@section('title', $employee->display_name)

@php
    $typeColors = [
        'check_in' => 'bg-green-100 text-green-800',
        'check_out' => 'bg-red-100 text-red-800',
        'break_out' => 'bg-yellow-100 text-yellow-800',
        'break_in' => 'bg-blue-100 text-blue-800',
        'overtime_in' => 'bg-purple-100 text-purple-800',
        'overtime_out' => 'bg-pink-100 text-pink-800',
    ];
    $hours = fn (int $minutes) => $minutes > 0 ? intdiv($minutes, 60) . 'h ' . str_pad($minutes % 60, 2, '0', STR_PAD_LEFT) . 'm' : '—';
@endphp

@section('content')
    <a href="{{ route('employees.index') }}" class="text-sm text-gray-600 hover:text-gray-900"><i class="fas fa-arrow-left mr-1"></i>All employees</a>

    <!-- Profile -->
    <div class="bg-white rounded-lg shadow p-6 flex flex-wrap items-center gap-6">
        <div class="h-16 w-16 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xl font-semibold">{{ $employee->initials }}</div>
        <div class="flex-1 min-w-[200px]">
            <h1 class="text-2xl font-semibold {{ $employee->name ? 'text-gray-900' : 'text-gray-400' }}">{{ $employee->name ?? 'No name yet' }}</h1>
            <p class="text-sm text-gray-500">
                {{ collect([$employee->designation, $employee->department])->filter()->implode(' · ') ?: 'No designation or department yet' }}
            </p>
        </div>
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-x-8 gap-y-2 text-sm">
            <div><dt class="text-gray-500">PIN</dt><dd class="font-medium text-gray-900">{{ $employee->pin }}</dd></div>
            <div><dt class="text-gray-500">Card</dt><dd class="font-medium text-gray-900">{{ $employee->card_number ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Phone</dt><dd class="font-medium text-gray-900">{{ $employee->phone ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Email</dt><dd class="font-medium text-gray-900">{{ $employee->email ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">First punch</dt><dd class="font-medium text-gray-900">{{ $firstPunch ? \Carbon\Carbon::parse($firstPunch)->format('M d, Y') : '—' }}</dd></div>
            <div><dt class="text-gray-500">Last punch</dt><dd class="font-medium text-gray-900">{{ $lastPunch ? \Carbon\Carbon::parse($lastPunch)->format('M d, Y H:i') : '—' }}</dd></div>
            <div><dt class="text-gray-500">Role on device</dt><dd class="font-medium text-gray-900">{{ $employee->privilege === null ? '—' : ($employee->isDeviceAdmin() ? 'Admin' : 'User') }}</dd></div>
            <div><dt class="text-gray-500">Synced from device</dt><dd class="font-medium text-gray-900">{{ $employee->synced_at?->diffForHumans() ?? 'not yet' }}</dd></div>
        </dl>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Monthly attendance -->
        <div class="lg:col-span-2 bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $month->format('F Y') }}</h2>
                <div class="flex items-center gap-2 text-sm">
                    <a href="{{ route('employees.show', [$employee, 'month' => $month->copy()->subMonth()->format('Y-m')]) }}" class="px-2 py-1 border rounded hover:bg-gray-50" title="Previous month"><i class="fas fa-chevron-left"></i></a>
                    <form method="GET" action="{{ route('employees.show', $employee) }}">
                        <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()" class="border-gray-300 border rounded-md px-2 py-1">
                    </form>
                    <a href="{{ route('employees.show', [$employee, 'month' => $month->copy()->addMonth()->format('Y-m')]) }}" class="px-2 py-1 border rounded hover:bg-gray-50" title="Next month"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
            <div class="px-6 py-4 grid grid-cols-3 gap-4 border-b border-gray-200 text-center">
                <div><div class="text-2xl font-semibold text-gray-900">{{ $monthTotals['days_present'] }}</div><div class="text-xs text-gray-500">days present</div></div>
                <div><div class="text-2xl font-semibold text-gray-900">{{ $hours($monthTotals['minutes']) }}</div><div class="text-xs text-gray-500">first-to-last punch, total</div></div>
                <div><div class="text-2xl font-semibold text-gray-900">{{ $monthTotals['punches'] }}</div><div class="text-xs text-gray-500">punches</div></div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Date', 'First punch', 'Last punch', 'Time', 'Punches'] as $heading)
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-sm">
                        @forelse ($days as $day)
                            <tr class="hover:bg-gray-50 align-top">
                                <td class="px-6 py-3 whitespace-nowrap text-gray-900">{{ $day['date']->format('D, M d') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-900">{{ $day['first']->format('H:i') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-900">{{ $day['last']?->format('H:i') ?? '—' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-900">{{ $hours($day['minutes']) }}</td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($day['punches'] as $punch)
                                            <span class="inline-flex px-2 py-0.5 text-xs rounded-full {{ $typeColors[$punch->punch_type] ?? 'bg-gray-100 text-gray-800' }}" title="{{ $punch->punch_type_label }}">
                                                {{ $punch->punch_time->format('H:i') }} {{ $punch->punch_type_label }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No punches in {{ $month->format('F Y') }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit details -->
        <form method="POST" action="{{ route('employees.update', $employee) }}" class="bg-white rounded-lg shadow p-6 space-y-4 self-start">
            @csrf
            @method('PUT')
            <h2 class="text-lg font-semibold text-gray-900">Details</h2>

            @foreach ([
                'name' => ['Name', 'text'],
                'designation' => ['Designation', 'text'],
                'department' => ['Department', 'text'],
                'phone' => ['Phone', 'tel'],
                'email' => ['Email', 'email'],
            ] as $field => [$label, $type])
                <div>
                    <label for="{{ $field }}" class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
                    <input type="{{ $type }}" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $employee->$field) }}"
                        class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm @error($field) border-red-400 @enderror">
                    @if ($field === 'name')
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $employee->name_from_device ? 'From the device. Typing a name here keeps it, even after the next device sync.' : 'Set here. Clear it to use the name from the device again.' }}
                        </p>
                    @endif
                </div>
            @endforeach

            <div>
                <label for="notes" class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                <textarea id="notes" name="notes" rows="3" class="w-full border-gray-300 border rounded-md px-3 py-2 text-sm">{{ old('notes', $employee->notes) }}</textarea>
            </div>

            <button class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Save</button>
        </form>
    </div>

    <!-- All punches -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-900">All punches <span class="text-sm font-normal text-gray-500">({{ number_format($recent->total()) }})</span></h2>
            <a href="{{ route('attendance.export', ['search' => $employee->pin]) }}" class="text-sm px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-download mr-1 text-purple-600"></i>Export CSV
            </a>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    @foreach (['Punch time', 'Type', 'Verification', 'Device'] as $heading)
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-sm">
                @forelse ($recent as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 whitespace-nowrap text-gray-900">{{ $log->punch_time->format('M d, Y H:i:s') }}</td>
                        <td class="px-6 py-3 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $typeColors[$log->punch_type] ?? 'bg-gray-100 text-gray-800' }}">{{ $log->punch_type_label }}</span>
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-gray-900">{{ ucfirst(str_replace('_', ' ', $log->verification_type ?? 'unknown')) }}</td>
                        <td class="px-6 py-3 whitespace-nowrap text-gray-500">{{ $log->device_sn ?? $log->device_ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No punches yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($recent->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">{{ $recent->links() }}</div>
        @endif
    </div>
@endsection
