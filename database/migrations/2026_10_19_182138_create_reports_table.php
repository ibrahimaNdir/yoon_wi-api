<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('route_id')->nullable()->constrained('routes');
            $table->foreignId('stop_id')->nullable()->constrained('stops');
            $table->string('reported_value', 100)->nullable();
            $table->string('search_origin', 100)->nullable();
            $table->string('search_destination', 100)->nullable();
            $table->string('comment', 500)->nullable();
            $table->string('status')->default('new');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('handled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
