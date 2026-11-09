<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingTodayTest extends TestCase
{
    use RefreshDatabase;

    /** Tests that a non-numeric room ID returns not found. */
    public function test_returns_404_when_room_id_is_not_numeric(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/rooms/abc/bookings/today')->assertNotFound();
    }
}
