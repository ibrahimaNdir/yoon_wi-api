<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->smallInteger('direction');
            $table->integer('seq');
            $table->foreignId('stop_id')->constrained('stops');
            $table->integer('seconds_from_prev')->nullable();
            $table->integer('seconds_from_prev_peak')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('data_sources');

            $table->primary(['route_id', 'direction', 'seq']);
        });

        DB::statement('CREATE INDEX route_stops_stop_idx ON route_stops (stop_id);');
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
