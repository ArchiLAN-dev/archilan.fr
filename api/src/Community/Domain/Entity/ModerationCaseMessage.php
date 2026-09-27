<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One message of a member's moderation case: what the member writes to the moderation (story 39.2) and what
 * the staff answers from the site (story 39.3), with the outcome of the bot's direct message carrying that
 * answer. Answers to the bot's DM (39.4) join the same thread.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_case_message')]
#[ORM\Index(name: 'idx_moderation_case_message_case', columns: ['case_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_moderation_case_message_discord', columns: ['discord_message_id'])]
final class ModerationCaseMessage
{
    public const string AUTHOR_MEMBER = 'member';
    public const string AUTHOR_STAFF = 'staff';
    public const string SOURCE_SITE = 'site';
    public const string SOURCE_DISCORD_DM = 'discord_dm';
    public const int MAX_LENGTH = 2000;

    /** Outcomes of the direct message carrying a staff reply (story 39.3). */
    public const string DM_SENT = 'sent';
    public const string DM_FAILED = 'failed';
    public const string DM_NOT_LINKED = 'not_linked';
    public const string DM_UNAVAILABLE = 'unavailable';
    /** A sanction already lifted or over when its job ran (story 39.9): the member is not told. */
    public const string DM_SUPERSEDED = 'superseded';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'case_id', type: 'string', length: 32)]
        private string $caseId,
        #[ORM\Column(name: 'author_user_id', type: 'string', length: 32)]
        private string $authorUserId,
        #[ORM\Column(name: 'author_role', type: 'string', length: 16)]
        private string $authorRole,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(type: 'string', length: 16)]
        private string $source,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'discord_dm_status', type: 'string', length: 16, nullable: true)]
        private ?string $discordDmStatus = null,
        #[ORM\Column(name: 'discord_message_id', type: 'string', length: 32, nullable: true)]
        private ?string $discordMessageId = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException an empty message, or one longer than {@see self::MAX_LENGTH} characters
     */
    public static function fromMember(string $caseId, string $memberId, string $body, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $caseId, $memberId, self::AUTHOR_MEMBER, self::checkedBody($body), self::SOURCE_SITE, $now);
    }

    /**
     * @throws \InvalidArgumentException an empty message, or one longer than {@see self::MAX_LENGTH} characters
     */
    public static function fromStaff(string $caseId, string $staffId, string $body, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $caseId, $staffId, self::AUTHOR_STAFF, self::checkedBody($body), self::SOURCE_SITE, $now);
    }

    /**
     * What the member answered the bot in private (story 39.4). Already sent, so a message too long is cut
     * rather than refused; an empty one is the caller's to skip.
     *
     * @throws \InvalidArgumentException an empty message
     */
    public static function fromMemberDirectMessage(string $caseId, string $memberId, string $content, \DateTimeImmutable $sentAt, string $discordMessageId): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $caseId,
            $memberId,
            self::AUTHOR_MEMBER,
            self::checkedBody(mb_substr(trim($content), 0, self::MAX_LENGTH)),
            self::SOURCE_DISCORD_DM,
            $sentAt,
            discordMessageId: $discordMessageId,
        );
    }

    /** The first outcome stands: a direct message is sent once, whatever retries follow. */
    public function recordDirectMessage(string $status): void
    {
        $this->discordDmStatus ??= $status;
    }

    private static function checkedBody(string $body): string
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A message holds 1 to %d characters.', self::MAX_LENGTH));
        }

        return $body;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCaseId(): string
    {
        return $this->caseId;
    }

    public function getAuthorUserId(): string
    {
        return $this->authorUserId;
    }

    public function getAuthorRole(): string
    {
        return $this->authorRole;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDiscordDmStatus(): ?string
    {
        return $this->discordDmStatus;
    }

    public function getDiscordMessageId(): ?string
    {
        return $this->discordMessageId;
    }
}
