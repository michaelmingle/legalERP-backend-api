<?php
// database/migrations/2024_01_01_000000_create_settings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, json, boolean, integer
            $table->string('group')->default('general'); // general, email, appearance, billing, security
            $table->timestamps();
            
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'group']);
        });
        
        // Insert default settings
        $this->insertDefaultSettings();
    }
    
    private function insertDefaultSettings()
    {
        // Default settings will be inserted when organization is created
        // via a model observer or seeder
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};