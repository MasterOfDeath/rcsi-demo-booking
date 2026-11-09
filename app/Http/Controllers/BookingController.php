<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    /** @return LengthAwarePaginator<int, Booking> */
    public function index(Request $request): LengthAwarePaginator
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $conflict = DB::select("SELECT id FROM bookings
            WHERE room_id = {$request->room_id}
            AND starts_at <= '{$request->ends_at}'
            AND ends_at >= '{$request->starts_at}'");

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        $start = Carbon::parse($request->starts_at);
        if ($start->hour < 9 || $start->hour >= 20) {
            return response()->json(['error' => 'Нерабочее время'], 422);
        }

        $booking = Booking::create($request->all());

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return Collection<int, array{time: string, author: string}> */
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
