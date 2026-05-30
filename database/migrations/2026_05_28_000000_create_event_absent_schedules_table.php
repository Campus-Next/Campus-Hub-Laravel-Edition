<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_absent_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->dateTime('run_at')->index();
            $table->dateTime('processed_at')->nullable()->index();
            $table->dateTime('cancelled_at')->nullable()->index();
            $table->timestamps();

            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_absent_schedules');
    }
};
