<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('document_number')->nullable()->unique();
            $table->foreignId('case_id')->nullable()->constrained('cases')->onDelete('set null');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->enum('confidentiality', ['public', 'internal', 'confidential', 'highly_confidential'])->default('confidential');
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['organization_id', 'type']);
            $table->index(['case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};