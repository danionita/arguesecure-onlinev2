<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin-controlled half of the assistant switch (PLAN-2.3-ai.md D25).
     *
     * There are deliberately two switches, not one, because they answer two
     * different questions. `config('assistant.enabled')` is a *deployment*
     * fact — does this installation run an inference container at all — and it
     * is set by whoever writes the compose file. This column is an
     * *instance* decision made by an admin through the settings page, on a
     * deployment that has the capability.
     *
     * Collapsing them into one would mean either an admin toggle that cannot
     * work because no runtime exists, or a deployment-level flag that silently
     * turns a feature on for every user the moment the container is added.
     * `public_assessments_allowed` is the existing precedent for the second
     * half: an instance-wide policy enforced server-side at the call site,
     * not merely reflected in the UI.
     *
     * Default false. A deployment that adds the container does not thereby
     * hand the feature to its users; somebody decides.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('assistant_enabled')->default(false)->after('public_assessments_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('assistant_enabled');
        });
    }
};
