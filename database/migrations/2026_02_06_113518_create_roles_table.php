<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('permissions')->nullable(); // Default permissions for this role
            $table->timestamps();
            
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_system']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->string('module')->nullable(); // e.g., 'cases', 'billing', 'documents'
            $table->string('action')->nullable(); // e.g., 'create', 'read', 'update', 'delete'
            $table->string('description')->nullable();
            $table->timestamps();
            
            $table->unique(['organization_id', 'name']);
            $table->index(['module', 'action']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};