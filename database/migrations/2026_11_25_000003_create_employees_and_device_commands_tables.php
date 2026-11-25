<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per person enrolled on the devices, keyed by their PIN (attendance_logs.device_user_id).
         * name / card_number / privilege come from the device; the rest is filled in on the dashboard.
         * These are not logins: dashboard accounts stay in `users`.
         */
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('pin')->unique();
            $table->string('name')->nullable();
            // false once someone edits the name on the dashboard, so a device sync doesn't overwrite it
            $table->boolean('name_from_device')->default(true);
            $table->string('card_number')->nullable();
            $table->unsignedTinyInteger('privilege')->nullable(); // 0 = user, 14 = device admin
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->string('device_sn')->nullable(); // device the details last came from
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        /*
         * Commands waiting for a device. The device collects them on its next /iclock/getrequest poll
         * and reports the result to /iclock/devicecmd.
         */
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->string('device_sn');
            $table->string('command');
            $table->string('status', 20)->default('pending'); // pending, sent, done, failed
            $table->integer('return_code')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['device_sn', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
        Schema::dropIfExists('employees');
    }
};
