<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - punch_type: enum(check_in, check_out, break_in, break_out) -> string, so overtime_in / overtime_out
 *   (device status 4 / 5) can be stored. Under MySQL strict mode the enum made those inserts fail.
 * - device_sn: serial number of the ADMS device that pushed the punch (null for punches pulled over UDP).
 * - unique (device_sn, device_user_id, punch_time): a device re-sends punches it isn't sure were received;
 *   this makes every re-send a no-op instead of a duplicate row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE attendance_logs MODIFY punch_type VARCHAR(20) NOT NULL");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite stores the enum as a CHECK constraint, which can't be altered: rebuild the table
            $this->rebuildForSqlite(fn (Blueprint $table) => $table->string('punch_type', 20));
        } else {
            Schema::table('attendance_logs', fn (Blueprint $table) => $table->string('punch_type', 20)->change());
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('device_sn')->nullable()->after('device_ip');
            $table->index('device_sn');
            $table->index('punch_time');
            $table->unique(['device_sn', 'device_user_id', 'punch_time'], 'attendance_logs_device_punch_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('attendance_logs_device_punch_unique');
            $table->dropIndex(['punch_time']);
            $table->dropIndex(['device_sn']);
            $table->dropColumn('device_sn');
        });

        $types = "'check_in','check_out','break_in','break_out'";
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Rows with overtime types would block the change back
            DB::table('attendance_logs')->whereIn('punch_type', ['overtime_in', 'overtime_out'])->update(['punch_type' => 'check_in']);
            DB::statement("ALTER TABLE attendance_logs MODIFY punch_type ENUM({$types}) NOT NULL");
        }
    }

    private function rebuildForSqlite(Closure $punchType): void
    {
        Schema::create('attendance_logs_new', function (Blueprint $table) use ($punchType) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('device_user_id');
            $table->string('device_ip');
            $table->timestamp('punch_time');
            $punchType($table);
            $table->string('verification_type')->nullable();
            $table->integer('work_code')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement('INSERT INTO attendance_logs_new SELECT id, user_id, device_user_id, device_ip, punch_time, punch_type, verification_type, work_code, is_processed, created_at, updated_at FROM attendance_logs');
        Schema::drop('attendance_logs');
        Schema::rename('attendance_logs_new', 'attendance_logs');

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index(['device_user_id', 'punch_time']);
            $table->index('device_ip');
        });
    }
};
