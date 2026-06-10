<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        // Strip leading+trailing double quotes left behind by the old
        // `value => json` cast (e.g. "USD" → USD) for string-typed rows.
        DB::statement(<<<'SQL'
            UPDATE settings
            SET value = TRIM(BOTH '"' FROM value)
            WHERE (type = 'string' OR type IS NULL)
              AND value LIKE '"%"'
              AND CHAR_LENGTH(value) >= 2
        SQL);

        // Boolean rows: "true" / "false" with surrounding quotes get stripped too.
        DB::statement(<<<'SQL'
            UPDATE settings
            SET value = TRIM(BOTH '"' FROM value)
            WHERE type = 'boolean'
              AND value IN ('"true"', '"false"')
        SQL);

        // Integer rows: "60" → 60.
        DB::statement(<<<'SQL'
            UPDATE settings
            SET value = TRIM(BOTH '"' FROM value)
            WHERE type = 'integer'
              AND value LIKE '"%"'
              AND CHAR_LENGTH(value) >= 2
        SQL);
    }

    public function down(): void
    {
        // Non-destructive cleanup; nothing to revert.
    }
};
