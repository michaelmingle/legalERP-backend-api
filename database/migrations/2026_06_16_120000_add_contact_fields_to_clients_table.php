<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the contact / profile fields the ClientController already validates
 * and inserts. Without these columns, `Client::create()` blows up with
 * "Unknown column 'mobile'" because `mobile` is in the model's $fillable.
 *
 * Also relaxes `client_number` to nullable — the controller validation
 * already treats it as nullable, but the DB constraint was NOT NULL UNIQUE,
 * causing a 500 whenever the frontend omitted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'mobile')) {
                $table->string('mobile', 20)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('clients', 'photo_url')) {
                $table->string('photo_url')->nullable()->after('mobile');
            }
            if (!Schema::hasColumn('clients', 'gender')) {
                $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('photo_url');
            }
            if (!Schema::hasColumn('clients', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('gender');
            }
            if (!Schema::hasColumn('clients', 'job_title')) {
                $table->string('job_title')->nullable()->after('date_of_birth');
            }
            if (!Schema::hasColumn('clients', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
            if (!Schema::hasColumn('clients', 'state')) {
                $table->string('state', 100)->nullable()->after('city');
            }
            if (!Schema::hasColumn('clients', 'postal_code')) {
                $table->string('postal_code', 20)->nullable()->after('state');
            }
            if (!Schema::hasColumn('clients', 'country')) {
                $table->string('country', 100)->nullable()->after('postal_code');
            }
        });

        // Make client_number nullable so the controller can auto-generate it.
        // Requires doctrine/dbal for ->change() on most setups; if that's not
        // installed, we fall back to raw SQL (MySQL/MariaDB).
        try {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('client_number')->nullable()->change();
            });
        } catch (\Throwable $e) {
            \DB::statement('ALTER TABLE clients MODIFY client_number VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['mobile','photo_url','gender','date_of_birth','job_title','city','state','postal_code','country'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        // We intentionally don't revert client_number back to NOT NULL —
        // existing rows may now have NULLs.
    }
};
