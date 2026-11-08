<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMTP settings can be set on the admin Email tab instead of in the
     * environment. Every column is nullable and empty means "not set here"; an
     * environment value for the same field wins (MailSettings). The password
     * is `text` because the `encrypted` cast stores a longer, base64-wrapped
     * payload. `mail_tested_fingerprint` is a hash of the settings that last
     * delivered a test message: mail counts as on only while it still matches.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('mail_host', 255)->nullable();
            $table->unsignedSmallInteger('mail_port')->nullable();
            $table->string('mail_encryption', 10)->nullable();
            $table->string('mail_username', 255)->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_from_address', 255)->nullable();
            $table->string('mail_from_name', 100)->nullable();
            $table->string('mail_tested_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'mail_host',
                'mail_port',
                'mail_encryption',
                'mail_username',
                'mail_password',
                'mail_from_address',
                'mail_from_name',
                'mail_tested_fingerprint',
            ]);
        });
    }
};
