<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes');
            $table->string('kind');
            $table->foreignId('from_stop_id')->nullable()->constrained('stops');
            $table->foreignId('to_stop_id')->nullable()->constrained('stops');
            $table->integer('min_stops')->nullable();
            $table->integer('max_stops')->nullable();
            $table->integer('amount_fcfa');
            $table->foreignId('source_id')->nullable()->constrained('data_sources');
            $table->date('verified_at')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fares');
    }
};
