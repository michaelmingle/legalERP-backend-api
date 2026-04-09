<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('full_name');
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->string('contact_number')->nullable();
            $table->string('contact_email')->nullable(); // personal email, may differ from login email
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_number')->nullable();
            $table->string('emergency_relation')->nullable();

            // Employment Information
            $table->string('employee_id')->unique();
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->date('hire_date')->nullable();
            $table->enum('employment_type', ['Full-Time', 'Part-Time', 'Contract'])->nullable();
            $table->enum('status', ['Active', 'Inactive', 'On Leave'])->default('Active');

            // Payroll Information
            $table->decimal('salary', 10, 2)->nullable();
            $table->string('allowance')->nullable();
            $table->string('deduction')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
