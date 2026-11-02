<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nested_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });

        Schema::create('nested_blocks_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nested_block_id')->index();
            $table->morphs('blockable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nested_blocks_pivot');
        Schema::dropIfExists('nested_blocks');
    }
};
