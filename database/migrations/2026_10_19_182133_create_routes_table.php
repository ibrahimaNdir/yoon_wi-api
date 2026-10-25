<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('mode');
            $table->string('short_name');
            $table->text('long_name')->nullable();
            $table->integer('headway_peak_min')->nullable();
            $table->integer('headway_offpeak_min')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('verification')->default('to_verify');
            $table->date('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
