<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // security.md §4: "Admin password reset sets a temporary password
        // shown once on screen and forces a change on next login." W8
        // could only do the "shown once" half — this column is the missing
        // backing state (see the W8 report's gap #1). Never fillable from
        // request input (App\Models\User::$fillable stays untouched); only
        // ever set by server-side code (an admin reset, or cleared when the
        // user sets their own password).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
