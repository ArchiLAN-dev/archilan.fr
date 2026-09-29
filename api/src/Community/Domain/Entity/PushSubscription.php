<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One browser on one device that accepted the site's push notifications (story 40.2). The endpoint is the
 * push service's address for that browser; `p256dh` and `auth` are the keys the payload is encrypted with.
 * A browser keeps its endpoint across logins, so the same endpoint follows whoever registers it last.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_endpoint', columns: ['endpoint'])]
#[ORM\Index(name: 'idx_push_subscription_user', columns: ['user_id'])]
final class PushSubscription
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,

        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
        private string $userId,

        #[ORM\Column(type: Types::TEXT)]
        private string $endpoint,

        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $p256dh,

        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $auth,

        #[ORM\Column(name: 'content_encoding', type: Types::STRING, length: 16)]
        private string $contentEncoding,

        #[ORM\Column(name: 'user_agent', type: Types::STRING, length: 255, nullable: true)]
        private ?string $userAgent,

        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function register(
        string $id,
        string $userId,
        string $endpoint,
        string $p256dh,
        string $auth,
        string $contentEncoding,
        ?string $userAgent,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $endpoint, $p256dh, $auth, $contentEncoding, $userAgent, $now);
    }

    /** The same browser registered again, possibly by another member after a change of account. */
    public function renew(string $userId, string $p256dh, string $auth, string $contentEncoding, ?string $userAgent): void
    {
        $this->userId = $userId;
        $this->p256dh = $p256dh;
        $this->auth = $auth;
        $this->contentEncoding = $contentEncoding;
        $this->userAgent = $userAgent;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function getContentEncoding(): string
    {
        return $this->contentEncoding;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
