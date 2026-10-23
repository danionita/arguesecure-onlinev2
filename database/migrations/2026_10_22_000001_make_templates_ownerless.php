<?php

use App\Domain\Templates\TemplateMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W141 (PLAN-2.7-admin-templates.md, T1 and T6): a template has no owner.
 *
 * `owner_user_id` becomes nullable, existing templates are made ownerless by
 * TemplateMigrator, and a CHECK then says what the application relies on: a row
 * is a template exactly when it has no owner. The CHECK goes on last because
 * the data would not satisfy it before the conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function ($table): void {
            $table->unsignedBigInteger('owner_user_id')->nullable()->change();
        });

        app(TemplateMigrator::class)->run();

        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_template_ownerless CHECK ((is_template = 1) = (owner_user_id IS NULL))');
    }

    public function down(): void
    {
        // A template had no owner to give back; the oldest admin takes them,
        // which is the pre-2.7 shape. With no admin there is nobody to give
        // them to, and failing before any change beats half a rollback.
        $admin = DB::table('users')->where('is_admin', true)->orderBy('id')->value('id');
        if ($admin === null && DB::table('assessments')->whereNull('owner_user_id')->exists()) {
            throw new RuntimeException('Cannot roll back W141: templates need an administrator to own them.');
        }

        DB::statement('ALTER TABLE assessments DROP CONSTRAINT assessments_template_ownerless');

        if ($admin !== null) {
            DB::table('assessments')->whereNull('owner_user_id')->update(['owner_user_id' => $admin]);
        }

        Schema::table('assessments', function ($table): void {
            $table->unsignedBigInteger('owner_user_id')->nullable(false)->change();
        });
    }
};
