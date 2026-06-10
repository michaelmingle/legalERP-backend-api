<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) users.organization_id from organizations.owner_id (owners get their org)
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'organization_id')
            && Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'owner_id')) {
            DB::statement("
                UPDATE users u
                INNER JOIN organizations o ON o.owner_id = u.id
                SET u.organization_id = o.id
                WHERE u.organization_id IS NULL
            ");
        }

        // 2) users.organization_id from employees.user_id where employees already has the org
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'organization_id')
            && Schema::hasColumn('employees', 'user_id')) {
            DB::statement("
                UPDATE users u
                INNER JOIN employees e ON e.user_id = u.id
                SET u.organization_id = e.organization_id
                WHERE u.organization_id IS NULL
                  AND e.organization_id IS NOT NULL
            ");
        }

        // 3) users.organization_id from clients.user_id (clients table has org_id)
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'organization_id')
            && Schema::hasColumn('clients', 'user_id')) {
            DB::statement("
                UPDATE users u
                INNER JOIN clients c ON c.user_id = u.id
                SET u.organization_id = c.organization_id
                WHERE u.organization_id IS NULL
                  AND c.organization_id IS NOT NULL
            ");
        }

        // 4) users.organization_id inferred from case assignments (lawyers / supervisors)
        //    Pick the most common org_id from cases assigned to that user.
        if (Schema::hasTable('cases') && Schema::hasColumn('cases', 'organization_id')) {
            DB::statement("
                UPDATE users u
                INNER JOIN (
                    SELECT assigned_to AS uid, organization_id AS org, COUNT(*) AS c
                    FROM cases
                    WHERE assigned_to IS NOT NULL AND organization_id IS NOT NULL
                    GROUP BY assigned_to, organization_id
                ) c1 ON c1.uid = u.id
                LEFT JOIN (
                    SELECT assigned_to AS uid, organization_id AS org, COUNT(*) AS c
                    FROM cases
                    WHERE assigned_to IS NOT NULL AND organization_id IS NOT NULL
                    GROUP BY assigned_to, organization_id
                ) c2 ON c2.uid = c1.uid AND c2.c > c1.c
                SET u.organization_id = c1.org
                WHERE u.organization_id IS NULL
                  AND c2.uid IS NULL
            ");

            DB::statement("
                UPDATE users u
                INNER JOIN (
                    SELECT supervisor AS uid, organization_id AS org, COUNT(*) AS c
                    FROM cases
                    WHERE supervisor IS NOT NULL AND organization_id IS NOT NULL
                    GROUP BY supervisor, organization_id
                ) s1 ON s1.uid = u.id
                LEFT JOIN (
                    SELECT supervisor AS uid, organization_id AS org, COUNT(*) AS c
                    FROM cases
                    WHERE supervisor IS NOT NULL AND organization_id IS NOT NULL
                    GROUP BY supervisor, organization_id
                ) s2 ON s2.uid = s1.uid AND s2.c > s1.c
                SET u.organization_id = s1.org
                WHERE u.organization_id IS NULL
                  AND s2.uid IS NULL
            ");
        }

        // 5) Backfill employees.organization_id from the linked user's organization
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'organization_id')
            && Schema::hasColumn('employees', 'user_id')) {
            DB::statement("
                UPDATE employees e
                INNER JOIN users u ON u.id = e.user_id
                SET e.organization_id = u.organization_id
                WHERE e.organization_id IS NULL
                  AND u.organization_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Backfill is non-destructive; intentionally not reverted.
    }
};
