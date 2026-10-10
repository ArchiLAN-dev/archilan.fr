<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * « Tu as joué avec » (story 43.2): the members a member played with, as cards ready to add. A card is only
 * given for a listable member (public slug, not banned, suspended or deleted), so the read asks for a few more
 * than shown.
 */
final readonly class FriendSuggestionsQuery
{
    public const int MAX_LIMIT = 20;

    public function __construct(
        private FriendSuggestionsQueryInterface $suggestions,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forViewer(string $userId, ?string $sessionId, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $rows = $this->suggestions->forUser($userId, $sessionId, $limit + 10);
        $cards = $this->directory->cards(array_map(static fn (array $row): string => $row['userId'], $rows));

        $suggestions = [];
        foreach ($rows as $row) {
            $card = $cards[$row['userId']] ?? null;
            if (null === $card) {
                continue;
            }
            $suggestions[] = [
                ...$card,
                'sessionsTogether' => $row['sessionsTogether'],
                'lastTitle' => $row['lastTitle'],
                'lastPlayedAt' => $row['lastPlayedAt'],
            ];
            if (\count($suggestions) === $limit) {
                break;
            }
        }

        return $suggestions;
    }
}
