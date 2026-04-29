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
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->onDelete('cascade');
            }
            if (!Schema::hasColumn('documents', 'file_name')) {
                $table->string('file_name')->nullable()->after('file_path');
            }
            if (!Schema::hasColumn('documents', 'file_size')) {
                $table->bigInteger('file_size')->nullable()->after('file_name');
            }
            if (!Schema::hasColumn('documents', 'file_type')) {
                $table->string('file_type')->nullable()->after('file_size');
            }
            if (!Schema::hasColumn('documents', 'description')) {
                $table->text('description')->nullable()->after('file_type');
            }
            if (!Schema::hasColumn('documents', 'confidentiality')) {
                $table->enum('confidentiality', ['public', 'confidential', 'highly_confidential'])->default('confidential')->after('description');
            }
            if (!Schema::hasColumn('documents', 'deleted_at')) {
                $table->softDeletes();
            }
            
            $table->index('organization_id');
            $table->index('case_id');
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_size', 'file_type', 'description', 'confidentiality', 'deleted_at']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
