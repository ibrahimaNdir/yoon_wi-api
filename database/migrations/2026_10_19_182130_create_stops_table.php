<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_station')->default(false);
            $table->date('verified_at')->nullable();
        });

        DB::statement("ALTER TABLE stops ADD COLUMN aliases text[] NOT NULL DEFAULT '{}';");
        DB::statement('ALTER TABLE stops ADD COLUMN geom geography(Point, 4326) NOT NULL;');
        DB::statement('CREATE INDEX stops_geom_idx ON stops USING gist (geom);');
    }

    public function down(): void
    {
        Schema::dropIfExists('stops');
    }
};
