<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email for in-app notifications. Off by default at the instance: an
     * administrator chooses which kinds may be mailed, and whether titles may
     * appear in the message. A person's own opt-outs are a list of kinds.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('mail_notify_mentions')->default(false);
            $table->boolean('mail_notify_assignments')->default(false);
            $table->boolean('mail_notify_summaries')->default(false);
            $table->boolean('mail_notify_titles')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('mail_notifications_off')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mail_notifications_off');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['mail_notify_mentions', 'mail_notify_assignments', 'mail_notify_summaries', 'mail_notify_titles']);
        });
    }
};
