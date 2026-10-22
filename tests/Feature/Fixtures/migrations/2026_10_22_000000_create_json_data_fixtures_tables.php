<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contests', function (Blueprint $table) {
            $table->id();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_en')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->nullable()->index();
            $table->string('handle')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('prizes_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prize_id')->index();
            $table->morphs('prizable');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prizes_pivot');
        Schema::dropIfExists('prizes');
        Schema::dropIfExists('games');
        Schema::dropIfExists('contests');
    }
};
