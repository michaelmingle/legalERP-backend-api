<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('logo_url')->nullable();
            $table->enum('industry', ['law_firm', 'corporate', 'government', 'non_profit', 'other'])->default('law_firm');
            $table->integer('employee_count')->default(1);
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->onDelete('set null');
            $table->enum('status', ['active', 'suspended', 'trial', 'inactive'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->decimal('monthly_revenue', 10, 2)->default(0);
            $table->boolean('is_verified')->default(false);
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('GHS');
            $table->string('language', 10)->default('en');
            $table->json('settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['subdomain', 'status']);
            $table->index('subscription_plan_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};