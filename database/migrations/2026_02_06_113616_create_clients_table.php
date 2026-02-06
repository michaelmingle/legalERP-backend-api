<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('client_number')->unique();
            $table->enum('type', ['individual', 'company', 'organization'])->default('individual');
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('tags')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'prospective', 'former'])->default('inactive');
            $table->foreignId('assigned_lawyer')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['organization_id', 'status']);
            $table->index(['full_name']);
            $table->index(['email', 'phone']);
            $table->index('client_number');
            $table->index('assigned_lawyer');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};