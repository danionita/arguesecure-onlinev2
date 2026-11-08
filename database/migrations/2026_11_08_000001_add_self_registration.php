<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-registration: off by default. A request waits in `pending_registrations`
     * (an address and nothing else: no name, no password) until its owner follows the emailed link;
     * only then does a `users` row exist. `users.self_registered_at` marks
     * those accounts so the daily cap can count them.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('registration_enabled')->default(false);
            $table->string('registration_domains', 1000)->nullable();
            $table->unsignedInteger('registration_daily_cap')->default(50);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('self_registered_at')->nullable()->index();
        });

        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('self_registered_at');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['registration_enabled', 'registration_domains', 'registration_daily_cap']);
        });
    }
};
