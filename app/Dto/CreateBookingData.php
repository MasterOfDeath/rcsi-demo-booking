<?php

namespace App\Dto;

class CreateBookingData
{
    public function __construct(
        public int $roomId,
        public string $title,
        public string $startsAt,
        public string $endsAt,
    ) {}

    /**
     * Creates booking data from validated request attributes.
     *
     * @param  array{room_id: int, title: string, starts_at: string, ends_at: string}  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            roomId: $attributes['room_id'],
            title: $attributes['title'],
            startsAt: $attributes['starts_at'],
            endsAt: $attributes['ends_at'],
        );
    }
}
