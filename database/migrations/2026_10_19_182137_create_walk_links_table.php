<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_links', function (Blueprint $table) {
            $table->foreignId('from_stop_id')->constrained('stops');
            $table->foreignId('to_stop_id')->constrained('stops');
            $table->integer('distance_m');
            $table->integer('walk_seconds');

            $table->primary(['from_stop_id', 'to_stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_links');
    }
};
