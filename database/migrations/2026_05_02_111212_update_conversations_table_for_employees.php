<?php
// database/migrations/2024_xxxxxx_update_conversations_table_for_employees.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateConversationsTableForEmployees extends Migration
{
    public function up()
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Add participant_type to identify the type of conversation
            $table->enum('conversation_type', ['client_lawyer', 'client_employee', 'employee_lawyer'])
                  ->default('client_lawyer')
                  ->after('lawyer_id');
            
            // Add employee_id for employee conversations
            $table->unsignedBigInteger('employee_id')->nullable()->after('lawyer_id');
            
            // Add foreign key for employee
            $table->foreign('employee_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn(['conversation_type', 'employee_id']);
        });
    }
}