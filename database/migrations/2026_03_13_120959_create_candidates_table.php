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
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email');
            $table->string('role'); // job applied for
            $table->date('date_applied');
            $table->string('attachments')->nullable(); // path to CV/file
            $table->enum('stage', [
                'applied',
                'reviewing',
                'interview',
                'hired',
                'rejected'
            ])->default('applied');
            $table->string('avatar')->nullable(); // profile image
            $table->foreignId('job_opening_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
