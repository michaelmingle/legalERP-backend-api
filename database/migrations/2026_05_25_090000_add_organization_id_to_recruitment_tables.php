<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_openings') && !Schema::hasColumn('job_openings', 'organization_id')) {
            Schema::table('job_openings', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                $table->index('organization_id');
            });
        }

        if (Schema::hasTable('candidates') && !Schema::hasColumn('candidates', 'organization_id')) {
            Schema::table('candidates', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                $table->index('organization_id');
            });
        }

        // Backfill candidates.organization_id from their job opening (which we'll backfill below).
        // First: backfill job_openings.organization_id from any owner-like signal. None exist,
        // so we leave legacy rows NULL — they'll show up org-wide.

        if (Schema::hasTable('candidates') && Schema::hasColumn('candidates', 'organization_id')
            && Schema::hasTable('job_openings') && Schema::hasColumn('job_openings', 'organization_id')) {
            DB::statement("
                UPDATE candidates c
                INNER JOIN job_openings j ON j.id = c.job_opening_id
                SET c.organization_id = j.organization_id
                WHERE c.organization_id IS NULL
                  AND j.organization_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('candidates') && Schema::hasColumn('candidates', 'organization_id')) {
            Schema::table('candidates', function (Blueprint $table) {
                $table->dropColumn('organization_id');
            });
        }
        if (Schema::hasTable('job_openings') && Schema::hasColumn('job_openings', 'organization_id')) {
            Schema::table('job_openings', function (Blueprint $table) {
                $table->dropColumn('organization_id');
            });
        }
    }
};
