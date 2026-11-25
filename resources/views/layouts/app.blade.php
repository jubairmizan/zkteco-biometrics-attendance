<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Attendance') · ZKTeco Attendance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>[x-cloak] { display: none !important; }</style>
</head>

<body class="bg-gray-100">
    <header class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center gap-8">
                    <a href="{{ route('attendance.index') }}" class="text-xl font-bold text-gray-900">ZKTeco Attendance</a>
                    <nav class="flex gap-1 text-sm">
                        @foreach (['attendance.index' => ['Attendance', 'fa-clock', 'attendance.*'], 'employees.index' => ['Employees', 'fa-id-card', 'employees.*']] as $route => [$label, $icon, $pattern])
                            <a href="{{ route($route) }}"
                                class="px-3 py-2 rounded-md {{ request()->routeIs($pattern) ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}">
                                <i class="fas {{ $icon }} mr-1"></i>{{ $label }}
                            </a>
                        @endforeach
                    </nav>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600"><i class="fas fa-user-circle mr-1"></i>{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-sm text-gray-600 hover:text-gray-900"><i class="fas fa-sign-out-alt mr-1"></i>Log out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        @if (session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>

</html>
