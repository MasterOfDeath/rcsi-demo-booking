<?php

namespace App\Http\Requests;

use App\Dto\CreateBookingData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /** Converts validated request input to booking data. */
    public function toData(): CreateBookingData
    {
        /** @var array{room_id: int, title: string, starts_at: string, ends_at: string} $validated */
        $validated = $this->validated();

        return CreateBookingData::fromArray($validated);
    }
}
