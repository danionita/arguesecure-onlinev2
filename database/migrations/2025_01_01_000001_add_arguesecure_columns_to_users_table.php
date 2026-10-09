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
        // schema.md §3.1: is_admin replaces the legacy `$user->name != "admin"` check;
        // is_active lets deactivation preserve authorship attribution without deleting the row.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_admin');
            $table->dateTime('last_login_at')->nullable()->after('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('is_admin', 'users_is_admin_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_is_admin_index');
            $table->dropColumn(['is_admin', 'is_active', 'last_login_at']);
        });
    }
};
