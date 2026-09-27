<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * What a sanctioned member sees of their exchange with the moderation (story 39.2): their messages and the
 * staff's replies (story 39.3), from their account when they can still log in, or with the contact pass when
 * they are banned or suspended. The replies come from "la modération", never from a named moderator.
 */
final readonly class MemberModerationContactQuery
{
    private const int MESSAGES_LIMIT = 50;

    public function __construct(
        private MemberModerationGatewayInterface $moderation,
        private ModerationActionRepositoryInterface $actions,
        private ModerationCaseRepositoryInterface $cases,
        private ModerationCaseMessageRepositoryInterface $messages,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A logged-in member: whether they have a sanction to talk about, and the exchange so far.
     *
     * @return array{available: bool, messages: list<array{id: string, author: string, source: string, body: string, createdAt: string}>}
     */
    public function forMember(string $userId): array
    {
        $case = $this->cases->findByTargetUserId($userId);

        return [
            'available' => null !== $case || [] !== $this->actions->forTarget($userId, 1),
            'messages' => null !== $case ? $this->thread($case->getId()) : [],
        ];
    }

    /**
     * A blocked member, named by their contact pass. Null once they are no longer blocked: the pass then
     * opens nothing, and the member logs in again to write from their account.
     *
     * @return array{status: 'banned'|'suspended', reason: string|null, suspendedUntil: string|null, messages: list<array{id: string, author: string, source: string, body: string, createdAt: string}>}|null
     */
    public function forBlockedMember(string $userId): ?array
    {
        $state = $this->moderation->currentState($userId);
        if (null === $state) {
            return null;
        }

        $suspended = null !== $state->suspendedUntil && new \DateTimeImmutable($state->suspendedUntil) > $this->clock->now();
        if (null === $state->bannedAt && !$suspended) {
            return null;
        }

        return [
            'status' => null !== $state->bannedAt ? 'banned' : 'suspended',
            'reason' => $state->reason,
            'suspendedUntil' => null !== $state->bannedAt ? null : $state->suspendedUntil,
            'messages' => $this->forMember($userId)['messages'],
        ];
    }

    /**
     * @return list<array{id: string, author: string, source: string, body: string, createdAt: string}>
     */
    private function thread(string $caseId): array
    {
        return array_map(static fn (ModerationCaseMessage $m): array => [
            'id' => $m->getId(),
            'author' => $m->getAuthorRole(),
            // Story 39.4: written on the site, or answered to the bot in private.
            'source' => $m->getSource(),
            'body' => $m->getBody(),
            'createdAt' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->messages->forCase($caseId, self::MESSAGES_LIMIT));
    }
}
