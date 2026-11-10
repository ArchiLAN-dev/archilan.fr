<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.5: one call to the Passeur, as the journals and the opened world's player's notice show it. `status` is
 * `pending`, `opened` or `failed`; `kind` says which pelles paid (`event` silver or `bronze`).
 */
final readonly class PasseurOpeningRow
{
    public function __construct(
        public string $id,
        public string $sessionId,
        public string $status,
        public string $buyerName,
        public string $absentName,
        public int $findingSlot,
        public int $locationId,
        public string $locationName,
        public string $itemName,
        public string $kind,
        public int $price,
        public int $share,
        public ?string $failureCode,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $settledAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sessionId' => $this->sessionId,
            'status' => $this->status,
            'buyerName' => $this->buyerName,
            'absentName' => $this->absentName,
            'locationName' => $this->locationName,
            'itemName' => $this->itemName,
            'kind' => $this->kind,
            'price' => $this->price,
            'share' => $this->share,
            'failureCode' => $this->failureCode,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'settledAt' => $this->settledAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
