<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    /** Tests that the author can delete a booking. */
    public function test_author_deletes_booking_and_returns_204(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    /** Tests that another user cannot delete the author's booking. */
    public function test_returns_403_and_preserves_booking_for_another_user(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        Sanctum::actingAs($otherUser);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    /** Tests that deleting a missing booking returns not found. */
    public function test_returns_404_when_booking_does_not_exist(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    /** Tests that unauthenticated users cannot delete bookings. */
    public function test_returns_401_when_unauthenticated(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }
}
