<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W133, D106: the OpenID Connect provider can be set on the admin
     * Authentication tab instead of in the environment. Every column is
     * nullable and empty means "not set here"; an environment value for the
     * same field wins (OidcConfig). The client secret is `text` because the
     * `encrypted` cast stores a longer, base64-wrapped payload.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('oidc_issuer', 255)->nullable();
            $table->string('oidc_client_id', 255)->nullable();
            $table->text('oidc_client_secret')->nullable();
            $table->string('oidc_button_label', 100)->nullable();
            $table->text('oidc_allowed_domains')->nullable();
            $table->boolean('oidc_trust_email')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'oidc_issuer',
                'oidc_client_id',
                'oidc_client_secret',
                'oidc_button_label',
                'oidc_allowed_domains',
                'oidc_trust_email',
            ]);
        });
    }
};
