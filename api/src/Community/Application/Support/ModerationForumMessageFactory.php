<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\DiscordBan;
use App\Community\Domain\Entity\DiscordBanNotice;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCaseMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the staff forum shows for a sanction of the site (story 39.1), a member's message to the moderation
 * (story 39.2) and a staff reply (story 39.3), and what the member receives of that reply in a direct
 * message. The tag names are the ones the staff gives the forum's tags.
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
    private const int STAFF_REPLY_COLOR = 0x9B59B6;

    private const array DM_OUTCOMES = [
        ModerationCaseMessage::DM_SENT => 'envoyé',
        ModerationCaseMessage::DM_FAILED => 'impossible (MP fermés ou serveur quitté)',
        ModerationCaseMessage::DM_NOT_LINKED => 'compte Discord non lié',
        ModerationCaseMessage::DM_UNAVAILABLE => 'synchronisation Discord désactivée',
        ModerationCaseMessage::DM_SUPERSEDED => 'non envoyé : sanction déjà levée',
    ];

    private const array SERVER_OUTCOMES = [
        ModerationAction::SERVER_BANNED => 'banni',
        ModerationAction::SERVER_LIFTED => 'sanction levée sur le serveur',
        ModerationAction::SERVER_TIMED_OUT => 'exclu temporairement',
        ModerationAction::SERVER_NOT_MEMBER => 'pas sur le serveur',
        ModerationAction::SERVER_NOT_LINKED => 'compte Discord non lié',
        ModerationAction::SERVER_UNAVAILABLE => 'synchronisation Discord désactivée',
        ModerationAction::SERVER_SUPERSEDED => 'non appliquée : sanction déjà levée',
        ModerationAction::SERVER_FAILED => 'échec (permission ou rôle du bot)',
    ];

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
     * @param string|null $dmStatus       how the bot's direct message told the member (story 39.4)
     * @param string|null $serverStatus   how the sanction was applied on the server (story 39.5)
     */
    public function forAction(ModerationAction $action, string $memberName, ?string $discordId, string $actorName, ?string $suspendedUntil, ?string $dmStatus = null, ?string $serverStatus = null): ModerationForumMessage
    {
        $label = self::LABELS[$action->getAction()] ?? $action->getAction();
        $site = rtrim($this->siteUrl, '/');

        $fields = [
            ['name' => 'Membre', 'value' => $this->member($memberName, $discordId)],
            ['name' => 'Modérateur', 'value' => $actorName],
        ];
        if (ModerationAction::ACTION_SUSPEND === $action->getAction() && null !== $suspendedUntil) {
            $fields[] = ['name' => 'Jusqu\'au', 'value' => self::parisTime($suspendedUntil)];
        }
        if (null !== $dmStatus) {
            $fields[] = ['name' => 'Message privé Discord', 'value' => self::DM_OUTCOMES[$dmStatus] ?? $dmStatus];
        }
        if (null !== $serverStatus) {
            $fields[] = ['name' => 'Serveur Discord', 'value' => self::SERVER_OUTCOMES[$serverStatus] ?? $serverStatus];
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
            ['name' => 'Écrit depuis', 'value' => ModerationCaseMessage::SOURCE_DISCORD_DM === $message->getSource() ? 'un MP au bot' : 'le site'],
            ['name' => 'Fiche', 'value' => rtrim($this->siteUrl, '/').'/admin/utilisateurs/'.$message->getAuthorUserId()],
        ], null);
    }

    /**
     * The staff's reply, as the staff forum records it: who answered, and whether the member got it in private.
     */
    public function forStaffReply(ModerationCaseMessage $reply, string $memberName, ?string $discordId, string $staffName, ?string $dmStatus): ModerationForumMessage
    {
        return new ModerationForumMessage('Réponse envoyée au membre', $reply->getBody(), self::STAFF_REPLY_COLOR, [
            ['name' => 'Membre', 'value' => $this->member($memberName, $discordId)],
            ['name' => 'Modérateur', 'value' => $staffName],
            ['name' => 'Message privé Discord', 'value' => self::DM_OUTCOMES[$dmStatus ?? ''] ?? 'non envoyé'],
        ], null);
    }

    /** The staff's reply, as the member receives it from the bot. The moderator stays unnamed: it is the team speaking. */
    public function forMemberDirectMessage(ModerationCaseMessage $reply): ModerationForumMessage
    {
        return new ModerationForumMessage('Réponse de la modération ArchiLAN', $reply->getBody(), self::STAFF_REPLY_COLOR, [
            ['name' => 'Pour répondre', 'value' => sprintf('Réponds à ce message, ou écris depuis ton espace compte (%s/compte), ou depuis la page de connexion si ton compte est suspendu ou banni.', rtrim($this->siteUrl, '/'))],
        ], null);
    }

    /**
     * The sanction, as the member receives it from the bot (story 39.4): what, why, until when, and how to
     * answer. The moderator stays unnamed: it is the team speaking.
     *
     * @param string|null $suspendedUntil the end of the suspension (ATOM), for a suspension
     */
    public function forSanctionDirectMessage(ModerationAction $action, ?string $suspendedUntil): ModerationForumMessage
    {
        $label = self::LABELS[$action->getAction()] ?? $action->getAction();
        $site = rtrim($this->siteUrl, '/');

        $fields = [];
        if (ModerationAction::ACTION_SUSPEND === $action->getAction() && null !== $suspendedUntil) {
            $fields[] = ['name' => 'Jusqu\'au', 'value' => self::parisTime($suspendedUntil).' (heure de Paris)'];
        }
        $fields[] = ModerationAction::ACTION_LIFT === $action->getAction()
            ? ['name' => 'Et maintenant', 'value' => sprintf('Tu retrouves l\'accès à ton compte : %s/connexion', $site)]
            : ['name' => 'Contester ou poser une question', 'value' => sprintf('Réponds à ce message : l\'équipe de modération le lira. Tu peux aussi écrire depuis ton espace compte (%s/compte), ou depuis la page de connexion si ton compte est suspendu ou banni.', $site)];

        return new ModerationForumMessage($label.' sur ArchiLAN', $action->getReason(), self::COLORS[$action->getAction()] ?? 0x95A5A6, $fields, null);
    }

    private const array UNAPPLIED_BANS = [
        DiscordBanNotice::REASON_UNLINKED => 'aucun compte lié : rien n\'est appliqué',
        DiscordBanNotice::REASON_ADMIN => 'compte d\'un admin du site : jamais sanctionné',
        DiscordBanNotice::REASON_PREEXISTING => 'ban antérieur à la synchronisation, à décider : rien n\'est appliqué sur le site',
    ];

    /**
     * A ban of the Discord server the site does not apply: no linked account, or an admin's (story 39.7), or one
     * present when the synchronisation was switched on (story 39.9), left to the staff's decision.
     *
     * @param string      $reason     one of the {@see DiscordBanNotice} REASON_ values
     * @param string|null $siteUserId the linked site account, if any
     */
    public function forUnappliedDiscordBan(DiscordBan $ban, ?string $author, string $reason, ?string $siteUserId): ModerationForumMessage
    {
        $fields = [
            ['name' => 'Compte Discord', 'value' => sprintf('%s (<@%s>)', $ban->username, $ban->discordUserId)],
            ['name' => 'Posé par', 'value' => $author ?? 'inconnu (journal d\'audit illisible)'],
            ['name' => 'Sur le site', 'value' => self::UNAPPLIED_BANS[$reason] ?? $reason],
        ];
        if (null !== $siteUserId) {
            $fields[] = ['name' => 'Fiche', 'value' => rtrim($this->siteUrl, '/').'/admin/utilisateurs/'.$siteUserId];
        }

        return new ModerationForumMessage('Ban posé sur Discord', $ban->reason ?? 'Sans raison', self::COLORS[ModerationAction::ACTION_BAN], $fields, self::LABELS[ModerationAction::ACTION_BAN]);
    }

    private static function parisTime(string $atom): string
    {
        return new \DateTimeImmutable($atom)->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
    }

    private function member(string $memberName, ?string $discordId): string
    {
        return null !== $discordId ? sprintf('%s (<@%s>)', $memberName, $discordId) : $memberName.' (compte Discord non lié)';
    }
}
