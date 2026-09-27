<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCaseMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the staff forum shows for a sanction of the site (story 39.1) and for a member's message to the
 * moderation (story 39.2). The tag names are the ones the staff gives the forum's tags.
 */
final readonly class ModerationForumMessageFactory
{
    private const array LABELS = [
        ModerationAction::ACTION_WARN => 'Avertissement',
        ModerationAction::ACTION_SUSPEND => 'Suspension',
        ModerationAction::ACTION_BAN => 'Ban',
        ModerationAction::ACTION_LIFT => 'Levée',
    ];

    private const int MEMBER_MESSAGE_COLOR = 0x3498DB;

    private const array COLORS = [
        ModerationAction::ACTION_WARN => 0xF1C40F,
        ModerationAction::ACTION_SUSPEND => 0xE67E22,
        ModerationAction::ACTION_BAN => 0xE74C3C,
        ModerationAction::ACTION_LIFT => 0x2ECC71,
    ];

    public function __construct(
        #[Autowire('%env(FRONTEND_ORIGIN)%')]
        private string $siteUrl,
    ) {
    }

    /**
     * @param string|null $suspendedUntil the end of the suspension (ATOM), for a suspension
     */
    public function forAction(ModerationAction $action, string $memberName, ?string $discordId, string $actorName, ?string $suspendedUntil): ModerationForumMessage
    {
        $label = self::LABELS[$action->getAction()] ?? $action->getAction();
        $site = rtrim($this->siteUrl, '/');

        $fields = [
            ['name' => 'Membre', 'value' => $this->member($memberName, $discordId)],
            ['name' => 'Modérateur', 'value' => $actorName],
        ];
        if (ModerationAction::ACTION_SUSPEND === $action->getAction() && null !== $suspendedUntil) {
            $fields[] = ['name' => 'Jusqu\'au', 'value' => new \DateTimeImmutable($suspendedUntil)->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i')];
        }
        $fields[] = ['name' => 'Fiche', 'value' => $site.'/admin/utilisateurs/'.$action->getTargetUserId()];
        if (null !== $action->getRelatedReportId()) {
            $fields[] = ['name' => 'Signalement lié', 'value' => sprintf('%s (%s/admin/moderation)', $action->getRelatedReportId(), $site)];
        }

        return new ModerationForumMessage($label, $action->getReason(), self::COLORS[$action->getAction()] ?? 0x95A5A6, $fields, $label);
    }

    /**
     * The member's words, as written: the forum never lets them ping anyone. No tag, so the post keeps the one
     * of the last sanction.
     */
    public function forMemberMessage(ModerationCaseMessage $message, string $memberName, ?string $discordId): ModerationForumMessage
    {
        return new ModerationForumMessage('Message du membre', $message->getBody(), self::MEMBER_MESSAGE_COLOR, [
            ['name' => 'Membre', 'value' => $this->member($memberName, $discordId)],
            ['name' => 'Écrit depuis', 'value' => 'le site'],
            ['name' => 'Fiche', 'value' => rtrim($this->siteUrl, '/').'/admin/utilisateurs/'.$message->getAuthorUserId()],
        ], null);
    }

    private function member(string $memberName, ?string $discordId): string
    {
        return null !== $discordId ? sprintf('%s (<@%s>)', $memberName, $discordId) : $memberName.' (compte Discord non lié)';
    }
}
