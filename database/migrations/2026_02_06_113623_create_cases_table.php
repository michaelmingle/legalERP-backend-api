<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('case_type_id')->constrained('case_types')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('case_name');
            $table->text('note')->nullable();
            $table->enum('status', [
                'draft', 
                'opened', 
                'in_progress', 
                'pending_review', 
                'pending_client', 
                'pending_court', 
                'settled', 
                'closed', 
                'archived'
            ])->default('pending_review');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('confidentiality', ['public', 'confidential', 'highly_confidential'])->default('confidential');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('document')->nullable()->constrained('documents')->onDelete('set null');
            $table->foreignId('supervisor')->constrained('users')->onDelete('cascade');
            $table->enum('billing_method', ['hourly', 'daily', 'weekly', 'monthly'])->default('hourly');
            $table->date('case_start_date');
            $table->date('expected_resolution_date')->nullable();
            $table->date('next_hearing_date')->nullable();
            $table->date('next_followup_date')->nullable();
            $table->decimal('rate', 8, 2)->default(0);
            $table->decimal('deposit', 8, 2)->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['organization_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index('case_number');
            $table->index('case_type_id');
            $table->index('priority');
            $table->index('next_hearing_date');
            $table->fulltext(['case_name', 'note']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};