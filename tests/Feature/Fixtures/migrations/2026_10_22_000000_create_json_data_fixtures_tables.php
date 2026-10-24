<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medias', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('mediables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->index();
            $table->morphs('mediable');
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('blocks_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->index();
            $table->morphs('blockable');
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->nullable();
            $table->foreignId('parent_id')->nullable()->index();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });

        Schema::create('contests', function (Blueprint $table) {
            $table->id();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_en')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });

        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->nullable()->index();
            $table->string('handle')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_en')->nullable();
            $table->string('name_sort')->nullable();
            $table->jsonb('data')->nullable();
            $table->jsonb('meta')->nullable();
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
        Schema::dropIfExists('offers');
        Schema::dropIfExists('prizes');
        Schema::dropIfExists('games');
        Schema::dropIfExists('contests');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('blocks_pivot');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('mediables');
        Schema::dropIfExists('medias');
    }
};
