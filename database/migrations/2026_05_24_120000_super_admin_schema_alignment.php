<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Allow system-wide settings rows (organization_id = NULL)
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'organization_id')) {
            try {
                Schema::table('settings', function (Blueprint $table) {
                    $table->dropForeign(['organization_id']);
                });
            } catch (\Throwable $e) {
                // foreign key may not exist on some installs
            }

            Schema::table('settings', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->change();
            });

            try {
                Schema::table('settings', function (Blueprint $table) {
                    $table->foreign('organization_id')
                        ->references('id')
                        ->on('organizations')
                        ->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // ignore if cannot recreate
            }
        }

        // 2) Align subscription_plans table with the SubscriptionPlan model
        if (Schema::hasTable('subscription_plans')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                if (!Schema::hasColumn('subscription_plans', 'organization_id')) {
                    $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                    $table->index('organization_id');
                }
                if (!Schema::hasColumn('subscription_plans', 'plan_name')) {
                    $table->string('plan_name')->nullable()->after('organization_id');
                }
                if (!Schema::hasColumn('subscription_plans', 'amount')) {
                    $table->decimal('amount', 10, 2)->default(0)->after('plan_name');
                }
                if (!Schema::hasColumn('subscription_plans', 'billing_cycle')) {
                    $table->string('billing_cycle')->default('monthly')->after('amount');
                }
                if (!Schema::hasColumn('subscription_plans', 'start_date')) {
                    $table->date('start_date')->nullable()->after('billing_cycle');
                }
                if (!Schema::hasColumn('subscription_plans', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
            });

            // Backfill plan_name/amount from name/pricing where present
            if (Schema::hasColumn('subscription_plans', 'name') && Schema::hasColumn('subscription_plans', 'plan_name')) {
                DB::statement('UPDATE subscription_plans SET plan_name = name WHERE plan_name IS NULL OR plan_name = ""');
            }
            if (Schema::hasColumn('subscription_plans', 'pricing') && Schema::hasColumn('subscription_plans', 'amount')) {
                DB::statement('UPDATE subscription_plans SET amount = pricing WHERE (amount IS NULL OR amount = 0)');
            }
        }

        // 3) Add description column to audit_logs (used by SuperAdmin ActivityController)
        if (Schema::hasTable('audit_logs') && !Schema::hasColumn('audit_logs', 'description')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->text('description')->nullable()->after('record_type');
            });
        }
    }

    public function down(): void
    {
        // Drop description column
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'description')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        // Drop subscription_plans added columns
        if (Schema::hasTable('subscription_plans')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                foreach (['organization_id', 'plan_name', 'amount', 'billing_cycle', 'start_date', 'end_date'] as $col) {
                    if (Schema::hasColumn('subscription_plans', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        // settings.organization_id revert intentionally skipped because rolling back to NOT NULL
        // could fail if system-wide rows exist.
    }
};
