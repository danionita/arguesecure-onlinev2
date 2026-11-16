<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notification kinds are allowed by default in a new setup (they still
     * send nothing until email itself is on). Only the column default
     * changes: a row that exists keeps what an administrator saved or the
     * earlier default gave it. Titles stay off, because they pass through
     * the mail server.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('mail_notify_mentions')->default(true)->change();
            $table->boolean('mail_notify_assignments')->default(true)->change();
            $table->boolean('mail_notify_summaries')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('mail_notify_mentions')->default(false)->change();
            $table->boolean('mail_notify_assignments')->default(false)->change();
            $table->boolean('mail_notify_summaries')->default(false)->change();
        });
    }
};
