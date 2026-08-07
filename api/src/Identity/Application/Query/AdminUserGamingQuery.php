<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Service\PersonalRunDrafts;

/**
 * The gaming panel of the admin user sheet (story 36.4): progression, linked accounts, personal runs and
 * finished-session history.
 *
 * Assembly only - all four reads existed. Personal runs go through PersonalRunDrafts (an application
 * service) rather than RunRepositoryInterface: the repository is PersonalRuns' *domain* contract, and
 * reaching into another context's domain from here is exactly the boundary this codebase keeps.
 */
final readonly class AdminUserGamingQuery
{
    private const int HISTORY_LIMIT = 50;

    public function __construct(
        private UserRepositoryInterface $users,
        private PersonalRunDrafts $runs,
        private PlayerHistoryQueryInterface $history,
        private CommunityLevelQuery $levels,
    ) {
    }

    /**
     * Null when no such account exists, so the controller answers 404 with a single Application call.
     */
    public function forUser(string $userId): ?AdminUserGaming
    {
        $user = $this->users->findById($userId);
        if (!$user instanceof User) {
            return null;
        }

        $mine = $this->runs->listMine($userId);

        return new AdminUserGaming(
            $this->levels->levelFor($userId),
            [
                'discordId' => $user->getDiscordId(),
                'discordUsername' => $user->getDiscordUsername(),
                'steamProfile' => $user->getSteamProfile(),
                // Twitch is deliberately absent: it is a social link on the community profile, not an
                // account linked at the Identity level, and it is already public on /joueurs/{slug}.
            ],
            $this->projectRuns($mine['owned']),
            $this->projectRuns($mine['joined']),
            $this->projectHistory($this->history->fetchForUser($userId)),
        );
    }

    /**
     * `listMine()` returns the rich payload the member's own space needs. An admin sheet wants a
     * one-line summary, plus the id - which story 36.6 will act on.
     *
     * @param list<array<string, mixed>> $runs
     *
     * @return list<array{id: string, title: string, status: string}>
     */
    private function projectRuns(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            $id = $run['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'title' => is_string($run['title'] ?? null) ? $run['title'] : '',
                'status' => is_string($run['status'] ?? null) ? $run['status'] : '',
            ];
        }

        return $out;
    }

    /**
     * PlayerHistoryQuery already unions the three shapes of finished game (event session, personal run,
     * weekly run); it is not re-implemented here, only narrowed and bounded.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{sessionId: string|null, context: string|null, game: string|null, finishedAt: string|null}>
     */
    private function projectHistory(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'sessionId' => is_string($row['session_id'] ?? null) ? $row['session_id'] : null,
                'context' => is_string($row['event_name'] ?? null) ? $row['event_name'] : null,
                'game' => is_string($row['game'] ?? null) ? $row['game'] : null,
                'finishedAt' => is_string($row['finished_at'] ?? null) ? $row['finished_at'] : null,
            ];
        }

        // Most recently finished first; rows without a date sort last rather than being dropped.
        usort($out, static fn (array $a, array $b): int => ($b['finishedAt'] ?? '') <=> ($a['finishedAt'] ?? ''));

        return array_slice($out, 0, self::HISTORY_LIMIT);
    }
}
