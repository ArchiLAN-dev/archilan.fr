<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Service\AccountModerationService;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use App\Community\Domain\ValueObject\ReportSeverity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Everything the moderation panel of an admin user sheet shows (story 36.2): the member's current access
 * state, the sanctions already applied, and the report pressure weighing on the account.
 *
 * Lives in Community, unlike the sheet's other panels which sit in Identity, for two reasons: moderation
 * belongs to this context, and the severity weighting is a Community domain value object
 * ({@see ReportSeverity}) that must not be imported - let alone duplicated - from elsewhere.
 */
final readonly class AccountModerationOverviewQuery
{
    private const int HISTORY_LIMIT = 50;
    private const int MESSAGES_LIMIT = 100;

    public function __construct(
        private MemberModerationGatewayInterface $moderation,
        private AccountModerationService $actions,
        private AccountReportScoreQueryInterface $reportScores,
        private CommunityUserDirectoryQueryInterface $cards,
        private ModerationCaseRepositoryInterface $cases,
        private ModerationCaseMessageRepositoryInterface $caseMessages,
        #[Autowire('%env(default::DISCORD_GUILD_ID)%')]
        private string $discordGuildId,
    ) {
    }

    /**
     * Null when the account does not exist (or is deleted), so the controller can answer 404 with a
     * single Application call.
     *
     * @return array{
     *     state: array{suspendedUntil: string|null, bannedAt: string|null, reason: string|null},
     *     unresolvedReportCount: int,
     *     severityScore: int,
     *     actions: list<array{id: string, action: string, reason: string, createdAt: string, actorId: string, actorName: string|null, relatedReportId: string|null, discordDm: string|null, discordServer: string|null}>,
     *     case: array{status: string, forumThreadUrl: string|null, messages: list<array{id: string, author: string, authorName: string|null, body: string, source: string, createdAt: string, discordDm: string|null}>}|null
     * }|null
     */
    public function forUser(string $userId): ?array
    {
        $state = $this->moderation->currentState($userId);
        if (null === $state) {
            return null;
        }

        $history = $this->actions->history($userId, self::HISTORY_LIMIT);
        $problems = $this->reportScores->unresolvedProblemsForAccount($userId);

        return [
            'state' => [
                'suspendedUntil' => $state->suspendedUntil,
                'bannedAt' => $state->bannedAt,
                'reason' => $state->reason,
            ],
            'unresolvedReportCount' => count($problems),
            // The single weighting table, applied where it lives. A copy anywhere else would drift the
            // first time a problem's weight is adjusted.
            'severityScore' => ReportSeverity::sum($problems),
            'actions' => $this->withActorNames($history),
            'case' => $this->moderationCase($userId),
        ];
    }

    /**
     * `AccountModerationService::history()` returns a bare `actorId`; an id tells a reviewer nothing.
     * Resolved in one batch, never one lookup per row.
     *
     * @param list<array{id: string, action: string, reason: string, createdAt: string, actorId: string, relatedReportId: string|null, discordDm: string|null, discordServer: string|null}> $history
     *
     * @return list<array{id: string, action: string, reason: string, createdAt: string, actorId: string, actorName: string|null, relatedReportId: string|null, discordDm: string|null, discordServer: string|null}>
     */
    private function withActorNames(array $history): array
    {
        $actorIds = array_values(array_unique(array_map(
            static fn (array $row): string => $row['actorId'],
            $history,
        )));

        // namesFor(), not cards(): cards() only returns listable members, so an admin without a public
        // profile - most of them - would have gone unnamed on every sanction they applied.
        $names = $this->cards->namesFor($actorIds);

        $out = [];
        foreach ($history as $row) {
            $out[] = [
                ...$row,
                // A since-deleted admin leaves the sanction in place, unnamed rather than hidden.
                'actorName' => $names[$row['actorId']] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Story 39.1: the member's moderation case and the link to its post in the staff forum on Discord; story
     * 39.2: its messages, oldest first, staff replies included (story 39.3).
     *
     * @return array{status: string, forumThreadUrl: string|null, messages: list<array{id: string, author: string, authorName: string|null, body: string, source: string, createdAt: string, discordDm: string|null}>}|null
     */
    private function moderationCase(string $userId): ?array
    {
        $case = $this->cases->findByTargetUserId($userId);
        if (null === $case) {
            return null;
        }

        $threadId = $case->getForumThreadId();

        return [
            'status' => $case->getStatus(),
            'forumThreadUrl' => null !== $threadId && '' !== $this->discordGuildId
                ? sprintf('https://discord.com/channels/%s/%s', $this->discordGuildId, $threadId)
                : null,
            'messages' => $this->messages($case->getId()),
        ];
    }

    /**
     * @return list<array{id: string, author: string, authorName: string|null, body: string, source: string, createdAt: string, discordDm: string|null}>
     */
    private function messages(string $caseId): array
    {
        $messages = $this->caseMessages->forCase($caseId, self::MESSAGES_LIMIT);
        $names = $this->cards->namesFor(array_values(array_unique(array_map(
            static fn (ModerationCaseMessage $m): string => $m->getAuthorUserId(),
            $messages,
        ))));

        return array_map(static fn (ModerationCaseMessage $m): array => [
            'id' => $m->getId(),
            'author' => $m->getAuthorRole(),
            'authorName' => $names[$m->getAuthorUserId()] ?? null,
            'body' => $m->getBody(),
            'source' => $m->getSource(),
            'createdAt' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
            // Story 39.3: how a staff reply reached the member on Discord; null while on its way.
            'discordDm' => $m->getDiscordDmStatus(),
        ], $messages);
    }
}
