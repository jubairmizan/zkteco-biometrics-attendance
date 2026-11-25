<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - device_status: the status code the device sent (0 = in, 1 = out, 2-5 = break / overtime), kept as-is.
 *   punch_type may differ from it because of the time rules (App\Services\PunchClassifier).
 * - attendance_status: "late" (check-in after the start time) or "early_leave" (check-out before the end time);
 *   null when on time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('device_status')->nullable()->after('punch_type');
            $table->string('attendance_status', 20)->nullable()->after('device_status');
            $table->index('attendance_status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['attendance_status']);
            $table->dropColumn(['device_status', 'attendance_status']);
        });
    }
};
