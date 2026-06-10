<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payrolls')) {
            Schema::create('payrolls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('payroll_no', 60)->unique();
                $table->tinyInteger('period_month');            // 1-12
                $table->smallInteger('period_year');            // e.g. 2026
                $table->decimal('basic_salary', 12, 2)->default(0);
                $table->decimal('allowance',    12, 2)->default(0);
                $table->decimal('deduction',    12, 2)->default(0);
                $table->decimal('tax',          12, 2)->default(0);
                $table->decimal('net_pay',      12, 2)->default(0);
                $table->string('status', 20)->default('pending'); // pending|on_hold|processed|paid
                $table->timestamp('processed_at')->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['employee_id', 'period_month', 'period_year'], 'payroll_employee_period_unique');
                $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
