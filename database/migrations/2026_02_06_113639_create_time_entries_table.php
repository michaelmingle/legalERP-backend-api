<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('case_id')->nullable()->constrained('cases')->onDelete('set null');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->string('description');
            $table->decimal('hours', 8, 2); // Can be decimal for partial hours
            $table->decimal('billable_hours', 8, 2)->nullable();
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('total_amount', 12, 2)->virtualAs('hours * hourly_rate');
            $table->decimal('billable_amount', 12, 2)->virtualAs('billable_hours * hourly_rate');
            $table->enum('status', ['draft', 'submitted', 'approved', 'billed', 'paid', 'write_off'])->default('draft');
            $table->date('entry_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('activity_type', ['research', 'drafting', 'meeting', 'court', 'phone', 'email', 'administrative', 'other'])->default('other');
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['organization_id', 'entry_date']);
            $table->index(['user_id', 'entry_date']);
            $table->index(['case_id', 'status']);
            $table->index('activity_type');
            $table->index('is_billable');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};