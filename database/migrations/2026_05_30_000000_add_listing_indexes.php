<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index('start_date');
            $table->index('updated_at');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->index(['event_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // LIKE '%term%' cannot use a B-tree index; FULLTEXT lets MySQL scale the
        // title/description search. Skipped on drivers without FULLTEXT (e.g. SQLite).
        if (DB::getDriverName() === 'mysql') {
            Schema::table('events', function (Blueprint $table) {
                $table->fullText(['title', 'description']);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('events', function (Blueprint $table) {
                $table->dropFullText(['title', 'description']);
            });
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['start_date']);
            $table->dropIndex(['updated_at']);
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'status']);
            $table->dropIndex(['user_id', 'status']);
        });
    }
};
