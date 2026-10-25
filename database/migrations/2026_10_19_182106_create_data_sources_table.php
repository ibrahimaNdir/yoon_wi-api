<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->text('description');
            $table->text('url')->nullable();
            $table->date('collected_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
