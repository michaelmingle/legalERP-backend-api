<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2)->virtualAs('quantity * unit_price');
            $table->string('unit')->nullable(); // hours, items, etc.
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->enum('type', ['service', 'product', 'expense', 'time', 'discount', 'tax'])->default('service');
            $table->timestamps();
            
            $table->index('invoice_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};