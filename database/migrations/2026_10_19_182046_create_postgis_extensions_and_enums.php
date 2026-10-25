<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent;');

        DB::statement("CREATE TYPE transport_mode AS ENUM ('rail', 'brt', 'bus', 'minibus');");
        DB::statement("CREATE TYPE network_status AS ENUM ('official', 'traditional');");
        DB::statement("CREATE TYPE fare_kind AS ENUM ('fixed', 'per_section', 'indicative');");
        DB::statement("CREATE TYPE verification_status AS ENUM ('to_verify', 'verified');");
        DB::statement("CREATE TYPE report_type AS ENUM ('wrong_price', 'line_not_existing', 'stop_moved', 'missing_coverage', 'other');");
        DB::statement("CREATE TYPE report_status AS ENUM ('new', 'accepted', 'rejected');");
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS report_status;');
        DB::statement('DROP TYPE IF EXISTS report_type;');
        DB::statement('DROP TYPE IF EXISTS verification_status;');
        DB::statement('DROP TYPE IF EXISTS fare_kind;');
        DB::statement('DROP TYPE IF EXISTS network_status;');
        DB::statement('DROP TYPE IF EXISTS transport_mode;');
    }
};
