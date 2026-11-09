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
    private const int MINIMUM_DURATION_MINUTES = 15;

    private const int MAXIMUM_DURATION_HOURS = 4;

    private const int BOOKING_STEP_MINUTES = 15;

    private const int WORKING_DAY_START_HOUR = 9;

    private const int WORKING_DAY_END_HOUR = 20;

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
        $minimumDurationInSeconds = self::MINIMUM_DURATION_MINUTES * 60;
        $maximumDurationInSeconds = self::MAXIMUM_DURATION_HOURS * 60 * 60;
        $bookingStepInSeconds = self::BOOKING_STEP_MINUTES * 60;
        $errors = [];

        if ($durationInSeconds < $minimumDurationInSeconds || $durationInSeconds > $maximumDurationInSeconds || $durationInSeconds % $bookingStepInSeconds !== 0) {
            $errors['ends_at'][] = __('bookings.invalid_duration', [
                'min' => self::MINIMUM_DURATION_MINUTES,
                'max' => self::MAXIMUM_DURATION_HOURS,
                'step' => self::BOOKING_STEP_MINUTES,
            ]);
        }

        if ($start->minute % self::BOOKING_STEP_MINUTES !== 0 || $start->second !== 0 || $start->microsecond !== 0) {
            $errors['starts_at'][] = __('bookings.invalid_start_grid', ['step' => self::BOOKING_STEP_MINUTES]);
        }

        if ($end->minute % self::BOOKING_STEP_MINUTES !== 0 || $end->second !== 0 || $end->microsecond !== 0) {
            $errors['ends_at'][] = __('bookings.invalid_end_grid', ['step' => self::BOOKING_STEP_MINUTES]);
        }

        $workingDayStart = $start->copy()->setTime(self::WORKING_DAY_START_HOUR, 0);
        $workingDayEnd = $start->copy()->setTime(self::WORKING_DAY_END_HOUR, 0);

        if ($start->lt($workingDayStart)) {
            $errors['starts_at'][] = __('bookings.starts_before_working_hours', [
                'time' => sprintf('%02d:00', self::WORKING_DAY_START_HOUR),
            ]);
        }

        if ($end->gt($workingDayEnd)) {
            $errors['ends_at'][] = __('bookings.ends_after_working_hours', [
                'time' => sprintf('%02d:00', self::WORKING_DAY_END_HOUR),
            ]);
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
