<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One message of a member's moderation case (story 39.2): what the member writes to the moderation, from
 * the site. Staff replies (39.3) and answers to the bot's DM (39.4) join the same thread.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_case_message')]
#[ORM\Index(name: 'idx_moderation_case_message_case', columns: ['case_id', 'created_at'])]
final class ModerationCaseMessage
{
    public const string AUTHOR_MEMBER = 'member';
    public const string SOURCE_SITE = 'site';
    public const int MAX_LENGTH = 2000;

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
    ) {
    }

    /**
     * @throws \InvalidArgumentException an empty message, or one longer than {@see self::MAX_LENGTH} characters
     */
    public static function fromMember(string $caseId, string $memberId, string $body, \DateTimeImmutable $now): self
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A message holds 1 to %d characters.', self::MAX_LENGTH));
        }

        return new self(bin2hex(random_bytes(16)), $caseId, $memberId, self::AUTHOR_MEMBER, $body, self::SOURCE_SITE, $now);
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
}
