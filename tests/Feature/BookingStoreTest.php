<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    /** Tests that a booking belongs to the authenticated user rather than a client-provided user ID. */
    public function test_creates_booking_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'user_id' => $otherUser->id,
        ]));

        $response->assertOk()->assertJsonPath('user_id', $user->id);
        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Планёрка',
        ]);
    }

    /** Tests that bookings may start exactly when another booking ends. */
    public function test_allows_bookings_that_touch_at_their_boundaries(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2027-01-11T07:00:00+00:00',
            'ends_at' => '2027-01-11T08:00:00+00:00',
        ]);
        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2027-01-11T09:00:00+00:00',
            'ends_at' => '2027-01-11T10:00:00+00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2027-01-11T11:00:00+03:00',
            'ends_at' => '2027-01-11T12:00:00+03:00',
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('bookings', 3);
    }

    /** Tests that overlapping intervals in different rooms are allowed. */
    public function test_allows_same_time_slot_in_a_different_room(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        $otherRoom = Room::factory()->for($room->office)->create();
        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2027-01-11T07:00:00+00:00',
            'ends_at' => '2027-01-11T08:00:00+00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($otherRoom));

        $response->assertOk()->assertJsonPath('room_id', $otherRoom->id);
        $this->assertDatabaseCount('bookings', 2);
    }

    /** Tests that every overlapping interval shape is rejected with a validation error. */
    #[DataProvider('overlappingIntervals')]
    public function test_returns_422_when_booking_overlaps_an_existing_one(string $startsAt, string $endsAt): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2027-01-11T07:00:00+00:00',
            'ends_at' => '2027-01-11T08:00:00+00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Provides intervals that overlap an existing booking.
     *
     * @return array<string, array{string, string}>
     */
    public static function overlappingIntervals(): array
    {
        return [
            'same interval' => ['2027-01-11T10:00:00+03:00', '2027-01-11T11:00:00+03:00'],
            'same interval with UTC offset' => ['2027-01-11T07:00:00+00:00', '2027-01-11T08:00:00+00:00'],
            'starts during existing booking' => ['2027-01-11T10:30:00+03:00', '2027-01-11T11:30:00+03:00'],
            'ends during existing booking' => ['2027-01-11T09:30:00+03:00', '2027-01-11T10:30:00+03:00'],
            'contains existing booking' => ['2027-01-11T09:00:00+03:00', '2027-01-11T12:00:00+03:00'],
            'inside existing booking' => ['2027-01-11T10:15:00+03:00', '2027-01-11T10:45:00+03:00'],
        ];
    }

    /** Tests that zero, negative, excessive, and off-step durations are rejected. */
    #[DataProvider('invalidDurations')]
    public function test_returns_422_when_duration_is_invalid(string $startsAt, string $endsAt): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * Provides intervals with invalid durations.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidDurations(): array
    {
        return [
            'zero duration' => ['2027-01-11T10:00:00+03:00', '2027-01-11T10:00:00+03:00'],
            'negative duration' => ['2027-01-11T11:00:00+03:00', '2027-01-11T10:00:00+03:00'],
            'shorter than fifteen minutes' => ['2027-01-11T10:00:00+03:00', '2027-01-11T10:10:00+03:00'],
            'longer than four hours' => ['2027-01-11T10:00:00+03:00', '2027-01-11T14:15:00+03:00'],
            'not a fifteen minute increment' => ['2027-01-11T10:00:00+03:00', '2027-01-11T10:20:00+03:00'],
        ];
    }

    /** Tests that both duration boundaries are accepted. */
    #[DataProvider('validDurationBoundaries')]
    public function test_allows_minimum_and_maximum_durations(string $endsAt): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'ends_at' => $endsAt,
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Provides the minimum and maximum allowed durations.
     *
     * @return array<string, array{string}>
     */
    public static function validDurationBoundaries(): array
    {
        return [
            'fifteen minutes' => ['2027-01-11T10:15:00+03:00'],
            'four hours' => ['2027-01-11T14:00:00+03:00'],
        ];
    }

    /** Tests that the start time must align with the fifteen-minute grid. */
    public function test_returns_422_when_start_is_not_on_fifteen_minute_grid(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2027-01-11T10:05:00+03:00',
            'ends_at' => '2027-01-11T11:05:00+03:00',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertDatabaseCount('bookings', 0);
    }

    /** Tests that the complete booking must fit within office working hours. */
    #[DataProvider('outsideWorkingHours')]
    public function test_returns_422_when_booking_is_outside_office_working_hours(
        string $startsAt,
        string $endsAt,
        string $errorField,
    ): void {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorField);
        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * Provides bookings outside working hours and their expected error fields.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function outsideWorkingHours(): array
    {
        return [
            'starts before opening' => ['2027-01-11T08:45:00+03:00', '2027-01-11T09:45:00+03:00', 'starts_at'],
            'ends after closing' => ['2027-01-11T19:00:00+03:00', '2027-01-11T20:15:00+03:00', 'ends_at'],
            'crosses midnight' => ['2027-01-11T19:00:00+03:00', '2027-01-11T23:00:00+03:00', 'ends_at'],
        ];
    }

    /** Tests working hours in the office timezone with a different client offset. */
    public function test_uses_office_timezone_for_working_hours_and_accepts_any_input_offset(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory()->novosibirsk())->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2027-01-11T02:00:00+00:00',
            'ends_at' => '2027-01-11T03:00:00+00:00',
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('bookings', 1);
    }

    /** Tests that ending exactly at closing time is allowed. */
    public function test_allows_booking_to_end_exactly_at_closing_time(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2027-01-11T19:00:00+03:00',
            'ends_at' => '2027-01-11T20:00:00+03:00',
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('bookings', 1);
    }

    /** Tests that valid past bookings are allowed. */
    public function test_allows_valid_booking_in_the_past(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2020-01-11T10:00:00+03:00',
            'ends_at' => '2020-01-11T11:00:00+03:00',
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Tests that malformed, missing, unsafe, and unknown input produces validation errors.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidInput')]
    public function test_returns_422_for_invalid_input(array $payload, string $errorField): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $payload === []
            ? []
            : $this->validPayload($room, $payload));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorField);
        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * Provides invalid request payloads and their expected error fields.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing fields' => [[], 'room_id'],
            'unknown room' => [['room_id' => 999999], 'room_id'],
            'SQL injection in room id' => [['room_id' => '1; DROP TABLE bookings; --'], 'room_id'],
            'invalid start date' => [['starts_at' => 'not-a-date'], 'starts_at'],
            'invalid end date' => [['ends_at' => 'not-a-date'], 'ends_at'],
            'empty title' => [['title' => ''], 'title'],
        ];
    }

    /** Tests that unauthenticated users cannot create bookings. */
    public function test_returns_401_when_unauthenticated(): void
    {
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', $this->validPayload($room))->assertUnauthorized();

        $this->assertDatabaseCount('bookings', 0);
    }

    /** Tests that PostgreSQL itself rejects an overlapping booking. */
    public function test_database_rejects_overlapping_bookings(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2027-01-11T07:00:00+00:00',
            'ends_at' => '2027-01-11T08:00:00+00:00',
        ]);

        try {
            DB::table('bookings')->insert([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'title' => 'Параллельная встреча',
                'starts_at' => '2027-01-11 07:30:00',
                'ends_at' => '2027-01-11 08:30:00',
            ]);

            $this->fail('The database accepted an overlapping booking.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
        }
    }

    /**
     * Builds a valid booking payload with test-specific overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2027-01-11T10:00:00+03:00',
            'ends_at' => '2027-01-11T11:00:00+03:00',
        ], $overrides);
    }
}
