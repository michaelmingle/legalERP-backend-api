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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
             $table->string('invoice_number')->unique(); // INV-5236

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lawyer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('issue_date');
            $table->date('due_date');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('vat', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->enum('status', ['draft', 'sent', 'paid', 'overdue'])->default('draft');

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
