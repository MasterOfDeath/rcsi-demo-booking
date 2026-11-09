<?php

namespace App\Services;

use App\Dto\CreateBookingData;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * Creates a booking after enforcing the booking rules.
     */
    public function create(User $user, CreateBookingData $data): Booking
    {
        $room = Room::with('office')->findOrFail($data->roomId);
        $start = Carbon::parse($data->startsAt)->setTimezone($room->office->timezone);
        $end = Carbon::parse($data->endsAt)->setTimezone($room->office->timezone);
        $startsAtUtc = $start->copy()->utc();
        $endsAtUtc = $end->copy()->utc();

        $this->ensureBookingIsValid($room, $start, $end, $startsAtUtc, $endsAtUtc);

        try {
            return Booking::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'title' => $data->title,
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'starts_at' => __('bookings.room_unavailable'),
            ]);
        }
    }

    /** Validates duration, alignment, working hours, and room availability. */
    private function ensureBookingIsValid(
        Room $room,
        Carbon $start,
        Carbon $end,
        Carbon $startsAtUtc,
        Carbon $endsAtUtc,
    ): void {
        $durationInSeconds = $end->getTimestamp() - $start->getTimestamp();
        $errors = [];

        if ($durationInSeconds < 15 * 60 || $durationInSeconds > 4 * 60 * 60 || $durationInSeconds % (15 * 60) !== 0) {
            $errors['ends_at'][] = __('bookings.invalid_duration');
        }

        if ($start->minute % 15 !== 0 || $start->second !== 0) {
            $errors['starts_at'][] = __('bookings.invalid_start_grid');
        }

        if ($end->minute % 15 !== 0 || $end->second !== 0) {
            $errors['ends_at'][] = __('bookings.invalid_end_grid');
        }

        $workingDayStart = $start->copy()->setTime(9, 0);
        $workingDayEnd = $start->copy()->setTime(20, 0);

        if ($start->lt($workingDayStart)) {
            $errors['starts_at'][] = __('bookings.starts_before_working_hours');
        }

        if ($end->gt($workingDayEnd)) {
            $errors['ends_at'][] = __('bookings.ends_after_working_hours');
        }

        if ($errors === [] && Booking::where('room_id', $room->id)
            ->where('starts_at', '<', $endsAtUtc)
            ->where('ends_at', '>', $startsAtUtc)
            ->exists()) {
            $errors['starts_at'][] = __('bookings.room_unavailable');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
