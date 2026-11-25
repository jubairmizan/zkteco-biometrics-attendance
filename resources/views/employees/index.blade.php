@extends('layouts.app')

@section('title', 'Employees')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Employees</h1>
            <p class="text-sm text-gray-500">
                Everyone enrolled on the devices, by PIN. Names come from the device; department, designation and contact details are added here.
            </p>
        </div>
        <form method="POST" action="{{ route('employees.fetch') }}">
            @csrf
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm" @disabled($devices->isEmpty())>
                <i class="fas fa-sync mr-1"></i>Fetch names from devices
            </button>
        </form>
    </div>

    @if ($pendingFetches > 0)
        <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-3 text-sm">
            <i class="fas fa-hourglass-half mr-2"></i>Waiting for {{ $pendingFetches }} device(s) to send their user list. Refresh in a minute.
        </div>
    @elseif ($unnamed > 0 && $devices->isNotEmpty())
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg px-4 py-3 text-sm">
            {{ $unnamed }} {{ Str::plural('PIN', $unnamed) }} without a name. Use "Fetch names from devices", or open a person and type a name.
        </div>
    @endif

    <form method="GET" action="{{ route('employees.index') }}" class="bg-white rounded-lg shadow p-4 flex gap-2">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, PIN, department or card"
            class="flex-1 border-gray-300 border rounded-md px-3 py-2 text-sm">
        <button class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-md text-sm"><i class="fas fa-search mr-1"></i>Search</button>
        @if ($search)
            <a href="{{ route('employees.index') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50" title="Clear">
                <i class="fas fa-times"></i>
            </a>
        @endif
    </form>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    @foreach (['Name', 'PIN', 'Department', 'Card', 'Today', 'Last punch'] as $heading)
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($employees as $employee)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 whitespace-nowrap">
                            <a href="{{ route('employees.show', $employee) }}" class="flex items-center gap-3 group">
                                <div class="h-9 w-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-semibold">{{ $employee->initials }}</div>
                                <div>
                                    <div class="text-sm font-medium {{ $employee->name ? 'text-gray-900' : 'text-gray-400' }} group-hover:text-blue-700">
                                        {{ $employee->name ?? 'No name yet' }}
                                        @if ($employee->isDeviceAdmin())
                                            <span class="ml-1 text-xs bg-gray-100 text-gray-600 rounded px-1.5 py-0.5">device admin</span>
                                        @endif
                                    </div>
                                    @if ($employee->designation)
                                        <div class="text-xs text-gray-500">{{ $employee->designation }}</div>
                                    @endif
                                </div>
                            </a>
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900">{{ $employee->pin }}</td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600">{{ $employee->department ?? '—' }}</td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600">{{ $employee->card_number ?? '—' }}</td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm">
                            @if ($employee->punches_today > 0)
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{ $employee->punches_today }} punch(es)</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600">
                            {{ $employee->last_punch_at ? \Carbon\Carbon::parse($employee->last_punch_at)->format('M d, Y H:i') : 'never' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                            @if ($search)
                                Nobody matches "{{ $search }}".
                            @else
                                No employees yet. They appear on their first punch, or when a device sends its user list.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if ($employees->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">{{ $employees->links() }}</div>
        @endif
    </div>
@endsection
