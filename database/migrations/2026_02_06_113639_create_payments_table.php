<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('account_number')->unique();
            $table->string('bank_name')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->enum('method', ['cash', 'check', 'credit_card', 'debit_card', 'bank_transfer', 'online', 'other'])->default('cash');
            $table->enum('status', ['paid', 'partial_payment']);
            $table->date('payment_date');
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->json('payment_details')->nullable(); // For storing card last 4 digits, bank details, etc.
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('recorded_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('processed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['organization_id', 'payment_date']);
            $table->index(['client_id', 'status']);
            $table->index(['invoice_id', 'status']);
            $table->index('account_number');
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};