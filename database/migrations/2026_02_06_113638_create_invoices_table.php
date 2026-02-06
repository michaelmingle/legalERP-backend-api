<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('case_id')->nullable()->constrained('cases')->onDelete('set null');
            $table->string('title')->nullable();
            $table->enum('type', ['billable_hour', 'flat_fee', 'contingency', 'retainer', 'expense', 'other'])->default('billable_hour');
            $table->enum('status', ['draft', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'void', 'refunded'])->default('draft');
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('responsible_lawyer');
            $table->text('description');
            $table->date('paid_date')->nullable();
            $table->string('rate')->nullable();
            $table->string('hours')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->virtualAs('total_amount - amount_paid');
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['organization_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['case_id', 'status']);
            $table->index('invoice_number');
            $table->index('issue_date');
            $table->index('due_date');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};