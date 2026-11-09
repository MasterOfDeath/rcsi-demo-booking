<?php

namespace App\Http\Requests;

use App\Dto\CreateBookingData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /** Matches extended ISO 8601 date-times with optional fractional seconds. */
    private const string ISO_8601_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

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
            'starts_at' => ['required', 'date', 'regex:'.self::ISO_8601_PATTERN],
            'ends_at' => ['required', 'date', 'regex:'.self::ISO_8601_PATTERN, 'after:starts_at'],
        ];
    }

    /**
     * Returns localized validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.regex' => __('bookings.iso8601'),
            'ends_at.regex' => __('bookings.iso8601'),
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
