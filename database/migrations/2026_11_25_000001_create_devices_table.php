<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices that talk to the server in ADMS (push) mode, keyed by serial number.
     * A row is created the first time a device connects.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->unique();
            $table->string('name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('push_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_punch_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
