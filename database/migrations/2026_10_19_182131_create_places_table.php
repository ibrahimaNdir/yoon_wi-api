<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
        });

        DB::statement("ALTER TABLE places ADD COLUMN aliases text[] NOT NULL DEFAULT '{}';");
        DB::statement('ALTER TABLE places ADD COLUMN geom geography(Point, 4326) NOT NULL;');
        DB::statement('CREATE INDEX places_geom_idx ON places USING gist (geom);');
        DB::statement('CREATE INDEX places_name_idx ON places USING gin (name gin_trgm_ops);');
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
