<?php

namespace App\Http\Controllers;

use App\Dto\CreateBookingData;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    /**
     * Returns the authenticated user's bookings.
     *
     * @return LengthAwarePaginator<int, Booking>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /** Creates a booking for the authenticated user. */
    public function store(Request $request, BookingService $bookingService): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $booking = $bookingService->create($request->user(), CreateBookingData::fromArray($validated));

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking);
    }

    /** Deletes a booking when requested by its author. */
    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * Returns today's bookings for the room.
     *
     * @return Collection<int, array{time: string, author: string}>
     */
    public function today(Room $room): Collection
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
