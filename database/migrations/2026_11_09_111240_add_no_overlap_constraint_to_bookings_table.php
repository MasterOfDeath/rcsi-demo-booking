<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_room_time_no_overlap EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at AT TIME ZONE 'UTC', ends_at AT TIME ZONE 'UTC', '[)') WITH &&)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_room_time_no_overlap');
    }
};
