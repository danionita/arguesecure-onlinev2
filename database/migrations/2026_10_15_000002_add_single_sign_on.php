<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single sign-on with OpenID Connect (W117, D74, D76, D79).
     *
     * `user_identities` links an account to the provider's stable identifier,
     * `(issuer, subject)`; an email address is only how the link is first made.
     * An account has at most one identity per issuer (one provider per
     * instance), and an identity belongs to one account, so both are unique.
     *
     * `instance_settings.sso_only` is D79's switch. Off by default: turning
     * SSO on must not stop anyone signing in.
     */
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer', 255);
            $table->string('subject', 255);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'issuer']);
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('sso_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('sso_only');
        });

        Schema::dropIfExists('user_identities');
    }
};
