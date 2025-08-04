<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="bg-gray-100">
    <div x-data="attendanceApp()" class="min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center py-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">ZKTeco Attendance</h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button @click="testConnection()"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition duration-200">
                            <i class="fas fa-wifi mr-2"></i>Test Connection
                        </button>
                        <button @click="syncLogs()"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200">
                            <i class="fas fa-sync mr-2"></i>Sync Logs
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Status Cards -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <!-- Connection Status -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-2 rounded-md"
                            :class="connectionStatus === 'connected' ? 'bg-green-100' : 'bg-red-100'">
                            <i class="fas fa-wifi text-xl"
                                :class="connectionStatus === 'connected' ? 'text-green-600' : 'text-red-600'"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Device Status</p>
                            <p class="text-lg font-semibold"
                                :class="connectionStatus === 'connected' ? 'text-green-600' : 'text-red-600'"
                                x-text="connectionStatus"></p>
                        </div>
                    </div>
                </div>

                <!-- Total Logs -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-md">
                            <i class="fas fa-list text-xl text-blue-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Logs</p>
                            <p class="text-lg font-semibold text-gray-900" x-text="totalLogs">{{ $logs->total() }}</p>
                        </div>
                    </div>
                </div>

                <!-- Today's Logs -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-yellow-100 rounded-md">
                            <i class="fas fa-calendar-day text-xl text-yellow-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Today's Logs</p>
                            <p class="text-lg font-semibold text-gray-900" x-text="todayLogs">0</p>
                        </div>
                    </div>
                </div>

                <!-- Last Sync -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-purple-100 rounded-md">
                            <i class="fas fa-clock text-xl text-purple-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Last Sync</p>
                            <p class="text-sm font-semibold text-gray-900" x-text="lastSync">Never</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Device Controls</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <button @click="getDeviceInfo()"
                            class="flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition duration-200">
                            <i class="fas fa-info-circle mr-2 text-blue-600"></i>
                            Device Info
                        </button>
                        <button @click="getDeviceUsers()"
                            class="flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition duration-200">
                            <i class="fas fa-users mr-2 text-green-600"></i>
                            Device Users
                        </button>
                        <button @click="syncDeviceUsers()"
                            class="flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-sync mr-2"></i>
                            Sync Device Users
                        </button>
                        <button @click="exportLogs()"
                            class="flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition duration-200">
                            <i class="fas fa-download mr-2 text-purple-600"></i>
                            Export CSV
                        </button>
                    </div>
                </div>
            </div>

            <!-- Attendance Logs Table -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Attendance Logs</h2>
                        <div class="flex items-center space-x-2">
                            <label class="flex items-center">
                                <input type="checkbox" x-model="autoSync"
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                <span class="ml-2 text-sm text-gray-600">Auto Sync (5min)</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    User</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Device ID</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Punch Time</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Verification</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Device IP</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($logs as $log)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div
                                                    class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                    <i class="fas fa-user text-gray-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ $log->user ? $log->user->name : 'Unknown User' }}
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    {{ $log->user ? $log->user->email : 'No email' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $log->device_user_id }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $log->punch_time->format('M d, Y H:i:s') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                        @if ($log->punch_type === 'check_in') bg-green-100 text-green-800
                                        @elseif($log->punch_type === 'check_out') bg-red-100 text-red-800
                                        @elseif($log->punch_type === 'break_in') bg-blue-100 text-blue-800
                                        @else bg-yellow-100 text-yellow-800 @endif">
                                            {{ ucfirst(str_replace('_', ' ', $log->punch_type)) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ ucfirst($log->verification_type ?? 'Unknown') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $log->device_ip }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                        {{ $log->is_processed ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                            {{ $log->is_processed ? 'Processed' : 'Pending' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                        No attendance logs found. Try syncing with the device.
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

        <!-- Notification Toast -->
        <div x-show="notification.show" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 transform translate-y-2"
            x-transition:enter-end="opacity-100 transform translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 transform translate-y-0"
            x-transition:leave-end="opacity-0 transform translate-y-2" class="fixed top-4 right-4 z-50">
            <div class="bg-white rounded-lg shadow-lg border p-4 max-w-sm"
                :class="notification.type === 'success' ? 'border-green-200' : 'border-red-200'">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i
                            :class="notification.type === 'success' ? 'fas fa-check-circle text-green-600' :
                                'fas fa-exclamation-circle text-red-600'"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900" x-text="notification.title"></p>
                        <p class="text-sm text-gray-600" x-text="notification.message"></p>
                    </div>
                    <div class="ml-auto pl-3">
                        <button @click="notification.show = false" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function attendanceApp() {
            return {
                connectionStatus: 'unknown',
                totalLogs: {{ $logs->total() }},
                todayLogs: 0,
                lastSync: 'Never',
                autoSync: false,
                syncInterval: null,
                notification: {
                    show: false,
                    type: 'success',
                    title: '',
                    message: ''
                },

                init() {
                    this.testConnection();
                    this.getTodayLogs();

                    // Watch autoSync toggle
                    this.$watch('autoSync', (value) => {
                        if (value) {
                            this.startAutoSync();
                        } else {
                            this.stopAutoSync();
                        }
                    });
                },

                showNotification(type, title, message) {
                    this.notification = {
                        show: true,
                        type,
                        title,
                        message
                    };
                    setTimeout(() => {
                        this.notification.show = false;
                    }, 5000);
                },

                async testConnection() {
                    try {
                        const response = await fetch('/attendance/test-connection');

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            this.connectionStatus = 'disconnected';
                            this.showNotification('error', 'Authentication Error',
                                'Please log in to access device controls');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        this.connectionStatus = data.status === 'success' ? 'connected' : 'disconnected';

                        this.showNotification(
                            data.status === 'success' ? 'success' : 'error',
                            'Connection Test',
                            data.message
                        );
                    } catch (error) {
                        this.connectionStatus = 'disconnected';
                        this.showNotification('error', 'Connection Error', 'Failed to test connection: ' + error
                            .message);
                    }
                },

                async syncLogs() {
                    try {
                        const response = await fetch('/attendance/sync', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute(
                                    'content')
                            }
                        });

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            this.showNotification('error', 'Authentication Error', 'Please log in to sync logs');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        this.showNotification('success', 'Sync Complete', data.message);
                        this.lastSync = new Date().toLocaleString();

                        // Refresh page to show new logs
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } catch (error) {
                        this.showNotification('error', 'Sync Error', 'Failed to sync logs: ' + error.message);
                    }
                },

                async getDeviceInfo() {
                    try {
                        const response = await fetch('/attendance/device-info');

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            this.showNotification('error', 'Authentication Error',
                                'Please log in to access device information');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        let message = `Device: ${data.device_name || 'Unknown'}\n`;
                        message += `Firmware: ${data.firmware_version || 'Unknown'}\n`;
                        message += `Users: ${data.user_count || 0}\n`;
                        message += `Logs: ${data.attendance_count || 0}`;

                        alert(message);
                    } catch (error) {
                        this.showNotification('error', 'Device Info Error', 'Failed to get device information: ' + error
                            .message);
                    }
                },

                async getDeviceUsers() {
                    try {
                        const response = await fetch('/attendance/device-users');

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            this.showNotification('error', 'Authentication Error',
                                'Please log in to access device users');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        this.showNotification('success', 'Device Users', `Found ${data.length} users on device`);
                    } catch (error) {
                        this.showNotification('error', 'Device Users Error', 'Failed to get device users: ' + error
                            .message);
                    }
                },

                async syncDeviceUsers() {
                    try {
                        const response = await fetch('/attendance/sync-device-users', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute(
                                    'content')
                            }
                        });

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            this.showNotification('error', 'Authentication Error',
                                'Please log in to sync device users');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const result = await response.json();

                        if (result.success) {
                            const {
                                synced_count,
                                updated_count,
                                total_device_users,
                                errors
                            } = result.data;
                            let message =
                                `Synchronized ${synced_count} new users and updated ${updated_count} existing users from ${total_device_users} device users.`;

                            if (errors.length > 0) {
                                message += ` ${errors.length} errors occurred.`;
                                console.warn('Sync errors:', errors);
                            }

                            this.showNotification('success', 'Device Users Synced', message);

                            // Refresh the logs to show any new users
                            this.loadLogs();
                        } else {
                            this.showNotification('error', 'Sync Failed', result.message ||
                                'Failed to sync device users');
                        }
                    } catch (error) {
                        this.showNotification('error', 'Sync Error', 'Failed to sync device users: ' + error.message);
                    }
                },

                exportLogs() {
                    window.open('/attendance/export', '_blank');
                },

                async getTodayLogs() {
                    try {
                        const today = new Date().toISOString().split('T')[0];
                        const response = await fetch(`/attendance/logs?start_date=${today}&end_date=${today}`);

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            console.log('Authentication required for today logs');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        this.todayLogs = data.total || 0;
                    } catch (error) {
                        console.error('Error fetching today logs:', error);
                    }
                },

                startAutoSync() {
                    this.syncInterval = setInterval(() => {
                        this.realtimeSync();
                    }, 5 * 60 * 1000); // 5 minutes

                    this.showNotification('success', 'Auto Sync', 'Auto sync enabled (every 5 minutes)');
                },

                stopAutoSync() {
                    if (this.syncInterval) {
                        clearInterval(this.syncInterval);
                        this.syncInterval = null;
                    }

                    this.showNotification('success', 'Auto Sync', 'Auto sync disabled');
                },

                async realtimeSync() {
                    try {
                        const response = await fetch('/attendance/realtime-sync');

                        // Check if response is a redirect to login
                        if (response.redirected && response.url.includes('/login')) {
                            this.showNotification('error', 'Authentication Error',
                                'Please log in to access realtime sync');
                            return;
                        }

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        if (data.new_logs > 0) {
                            this.showNotification('success', 'New Logs', `Synced ${data.new_logs} new attendance logs`);
                            this.lastSync = new Date().toLocaleString();

                            // Refresh page to show new logs
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        }
                    } catch (error) {
                        console.error('Realtime sync error:', error);
                        this.showNotification('error', 'Realtime Sync Error', 'Failed to perform realtime sync: ' +
                            error.message);
                    }
                }
            }
        }
    </script>
</body>

</html>
