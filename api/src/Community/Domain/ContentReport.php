<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user report on a piece of content, feeding the admin moderation queue (story 30.13). One report per
 * (reporter, target). Created in story 30.10 (report a comment); resolution lands with the admin panel.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_content_report')]
#[ORM\UniqueConstraint(name: 'uniq_community_report', columns: ['reporter_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_community_report_resolved', columns: ['resolved_at'])]
final class ContentReport
{
    public const TARGET_COMMENT = 'comment';
    public const TARGET_PROFILE = 'profile';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'reporter_id', type: 'string', length: 32)]
        private string $reporterId,
        #[ORM\Column(name: 'target_type', type: 'string', length: 16)]
        private string $targetType,
        #[ORM\Column(name: 'target_id', type: 'string', length: 32)]
        private string $targetId,
        #[ORM\Column(type: 'string', length: 500)]
        private string $reason,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        /** Story 30.28: structured "Type de signalement" (see ReportCategory). */
        #[ORM\Column(type: 'string', length: 32, options: ['default' => ReportCategory::OTHER])]
        private string $category = ReportCategory::OTHER,
        /** Story 30.28: "Contenu problématique" driving severity (see ReportProblem / ReportSeverity). */
        #[ORM\Column(type: 'string', length: 32, options: ['default' => ReportProblem::OTHER])]
        private string $problem = ReportProblem::OTHER,
        /** Story 30.28: optional free-text the reporter added. */
        #[ORM\Column(name: 'report_comment', type: 'string', length: 500, nullable: true)]
        private ?string $comment = null,
        #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $resolvedAt = null,
        #[ORM\Column(name: 'resolved_by', type: 'string', length: 32, nullable: true)]
        private ?string $resolvedBy = null,
    ) {
    }

    public static function create(
        string $reporterId,
        string $targetType,
        string $targetId,
        string $reason,
        \DateTimeImmutable $now,
        string $category = ReportCategory::OTHER,
        string $problem = ReportProblem::OTHER,
        ?string $comment = null,
    ): self {
        return new self(bin2hex(random_bytes(16)), $reporterId, $targetType, $targetId, $reason, $now, $category, $problem, $comment);
    }

    public function resolve(string $resolvedBy, \DateTimeImmutable $now): void
    {
        $this->resolvedAt = $now;
        $this->resolvedBy = $resolvedBy;
    }

    public function isResolved(): bool
    {
        return null !== $this->resolvedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getReporterId(): string
    {
        return $this->reporterId;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getProblem(): string
    {
        return $this->problem;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /** Severity weight of this report's problem (story 30.28). */
    public function severity(): int
    {
        return ReportSeverity::weight($this->problem);
    }

    /** Whether this is a low-signal "Autre/Autre/sans commentaire" report (story 30.28). */
    public function isUncategorized(): bool
    {
        return ReportSeverity::isUncategorized($this->category, $this->problem, $this->comment);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?string
    {
        return $this->resolvedBy;
    }
}
