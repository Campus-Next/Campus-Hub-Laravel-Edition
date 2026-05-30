<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['registered', 'attended', 'absent', 'cancelled'])->default('registered');
            $table->string('unique_code', 8)->nullable();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
            $table->unique(['event_id', 'unique_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participants');
    }
};
