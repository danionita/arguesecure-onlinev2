<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // W8's InstanceSettings persisted this durable configuration through
        // Cache::forever() because no table existed and database/migrations
        // was outside that package's ownership (see the W8 report's gap
        // #2) — a cache flush silently reverted the instance to defaults.
        // This is a deliberate singleton row, not a per-key table: there is
        // exactly one instance, so one row with a CHECK pinning its id is
        // simpler than a key/value table and gives every setting a real,
        // typed column. `registration_locked` is deliberately absent — see
        // App\Domain\Accounts\InstanceSettings for why.
        Schema::create('instance_settings', function (Blueprint $table) {
            // Not $table->id(): MariaDB rejects a CHECK constraint that
            // references an AUTO_INCREMENT column ("Function or expression
            // 'AUTO_INCREMENT' cannot be used in the CHECK clause"). The
            // singleton row's id is always set explicitly to 1 by
            // App\Models\InstanceSetting::singleton(), so no increment
            // behaviour is needed.
            $table->unsignedBigInteger('id')->primary();
            $table->char('default_currency', 3)->default('EUR');
            $table->string('default_scale_calibration', 64)->default('default');
            $table->boolean('public_assessments_allowed')->default(true);
            $table->unsignedInteger('session_lifetime_minutes')->default(480);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE instance_settings ADD CONSTRAINT chk_instance_settings_singleton CHECK (id = 1)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instance_settings');
    }
};
