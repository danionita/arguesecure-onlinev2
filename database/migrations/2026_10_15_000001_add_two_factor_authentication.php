<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-factor authentication with an authenticator app (W116, D77, D78).
     *
     * `two_factor_secret` and `two_factor_recovery_codes` hold ciphertext
     * (the model casts them with the app key), so they are `text`, not a
     * length the plaintext suggests. Both are null until an enrolment is
     * *confirmed* with a code from the new secret; `two_factor_confirmed_at`
     * is what says an account has the factor, so a half-finished enrolment
     * can never lock anyone out.
     *
     * `two_factor_last_step` is the RFC 6238 time step of the last code
     * accepted. A code is accepted only for a step greater than it, which is
     * what makes a code single-use inside its 30-second (plus one step either
     * side) validity.
     *
     * `instance_settings.require_two_factor` is D78's switch. It defaults off:
     * turning it on for an existing instance would otherwise send every
     * account to enrolment at its next sign-in with no warning.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('must_change_password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};
