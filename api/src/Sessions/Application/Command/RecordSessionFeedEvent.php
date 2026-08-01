<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Repository\SessionFeedEventRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Persists a game feed event pushed by the bridge (story 32.6).
 *
 * **Item**, **hint** and **goal** events are kept (story 32.12) - items feed the timeline and
 * per-player check curves, a hint marks intent, a goal marks completion. Chat/join/part/system
 * events stay ignored (noise). The pushed shape is `{type, text, timestamp, item:{id,name,flags},
 * location:{id,name}, sender:{slot,name,game}, receiver:{slot,name,game}}`; an AP hint carries the
 * same origin shape as an item event, and a goal only its `sender` (the finishing player), so the
 * one row shape serves all three - unresolved parts stay null. `type` is already mapped to
 * `item-received` by the controller for items; hints and goals pass through as `hint`/`goal`.
 * `item.flags` are the AP classification bits (1 = progression, story 32.9); an older bridge omits
 * them and the row keeps null.
 */
final readonly class RecordSessionFeedEvent
{
    public function __construct(
        private SessionFeedEventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<array-key, mixed> $event
     */
    public function record(string $sessionId, array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        if (!\in_array($type, SessionFeedEvent::PERSISTED_TYPES, true)) {
            return;
        }

        $item = self::subArray($event, 'item');
        $location = self::subArray($event, 'location');
        $sender = self::subArray($event, 'sender');
        $receiver = self::subArray($event, 'receiver');

        $this->events->save(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $sessionId,
            $type,
            is_string($event['text'] ?? null) ? $event['text'] : '',
            $this->occurredAt($event),
            self::intOrNull($item, 'id'),
            self::stringOrNull($item, 'name'),
            self::intOrNull($item, 'flags'),
            self::intOrNull($location, 'id'),
            self::stringOrNull($location, 'name'),
            self::intOrNull($sender, 'slot'),
            self::stringOrNull($sender, 'name'),
            self::stringOrNull($sender, 'game'),
            self::intOrNull($receiver, 'slot'),
            self::stringOrNull($receiver, 'name'),
            self::stringOrNull($receiver, 'game'),
        ));
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private function occurredAt(array $event): \DateTimeImmutable
    {
        $ts = $event['timestamp'] ?? null;
        if (is_string($ts) && '' !== $ts) {
            try {
                return new \DateTimeImmutable($ts);
            } catch (\Exception) {
                // A malformed timestamp falls back to now rather than dropping the event.
            }
        }

        return $this->clock->now();
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return array<array-key, mixed>
     */
    private static function subArray(array $event, string $key): array
    {
        $value = $event[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
