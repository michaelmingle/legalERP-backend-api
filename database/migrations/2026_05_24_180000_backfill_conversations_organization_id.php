<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('conversations') || !Schema::hasColumn('conversations', 'organization_id')) {
            return;
        }

        // Backfill organization_id from the client user's org, then the lawyer user's org.
        DB::statement("
            UPDATE conversations c
            INNER JOIN users u ON u.id = c.client_id
            SET c.organization_id = u.organization_id
            WHERE c.organization_id IS NULL
              AND u.organization_id IS NOT NULL
        ");

        DB::statement("
            UPDATE conversations c
            INNER JOIN users u ON u.id = c.lawyer_id
            SET c.organization_id = u.organization_id
            WHERE c.organization_id IS NULL
              AND u.organization_id IS NOT NULL
        ");

        // Also backfill messages.organization_id from their conversation, where missing.
        if (Schema::hasTable('messages') && Schema::hasColumn('messages', 'organization_id')) {
            DB::statement("
                UPDATE messages m
                INNER JOIN conversations c ON c.id = m.conversation_id
                SET m.organization_id = c.organization_id
                WHERE m.organization_id IS NULL
                  AND c.organization_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Backfill is non-destructive; intentionally not reverted.
    }
};
