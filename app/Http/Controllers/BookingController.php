<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    public function store(StoreBookingRequest $request, BookingService $bookingService): JsonResponse
    {
        $booking = $bookingService->create($request->user(), $request->toData());

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking);
    }

    /** Deletes a booking when requested by its author. */
    public function destroy(Request $request, Booking $booking): Response
    {
        abort_if($booking->user_id !== $request->user()->id, 403, __('bookings.only_author_can_cancel'));

        $booking->delete();

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
