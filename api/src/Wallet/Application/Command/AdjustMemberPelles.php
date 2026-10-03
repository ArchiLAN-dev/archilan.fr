<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Identity\Domain\Repository\AdminUserActionAuditRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;

/**
 * An admin credits or debits a member's pelles from their sheet (story 41.1 AC7): a happening, a gesture,
 * a correction. The reason is free text and shows in the member's history; the action is traced in the
 * admin journal and the member is told once it is committed. An admin never adjusts their own wallet (AC9).
 */
final readonly class AdjustMemberPelles
{
    public const int MAX_AMOUNT = 10000;

    public function __construct(
        private RecordPelleMovement $record,
        private AdminUserActionAuditRepositoryInterface $audits,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ForbiddenException  when the admin targets their own wallet
     * @throws ValidationException when the request is malformed or a debit would go below zero
     */
    public function adjust(
        string $adminId,
        string $targetUserId,
        string $direction,
        int $amount,
        string $kind,
        ?string $eventId,
        string $reason,
    ): RecordedPelleMovement {
        if ($adminId === $targetUserId) {
            throw new ForbiddenException('Tu ne peux pas créditer ou débiter ton propre portefeuille.', 'forbidden');
        }

        $credit = match ($direction) {
            'credit' => true,
            'debit' => false,
            default => throw new ValidationException('Sens inconnu.', ['direction' => ['Choisis crédit ou débit.']], 'invalid_direction'),
        };
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new ValidationException(sprintf('Le montant va de 1 à %d pelles.', self::MAX_AMOUNT), ['amount' => ['Montant hors bornes.']], 'invalid_amount');
        }
        $pelleKind = PelleKind::tryFrom($kind);
        if (null === $pelleKind) {
            throw new ValidationException('Type de pelles inconnu.', ['kind' => ['Type inconnu.']], 'invalid_kind');
        }
        if (PelleKind::Event === $pelleKind && (null === $eventId || '' === $eventId)) {
            throw new ValidationException('Des pelles d\'événement se rattachent à un événement.', ['eventId' => ['Choisis un événement.']], 'event_required');
        }
        $label = trim($reason);
        if ('' === $label || mb_strlen($label) > PelleMovement::LABEL_MAX_LENGTH) {
            throw new ValidationException('Le motif est obligatoire (200 caractères au plus).', ['reason' => ['Motif obligatoire.']], 'invalid_reason');
        }

        $signed = $credit ? $amount : -$amount;
        $recorded = $this->record->record(
            new RecordPelleMovementInput(
                $targetUserId,
                $signed,
                $pelleKind,
                PelleKind::Event === $pelleKind ? $eventId : null,
                $credit ? PelleReason::AdminCredit : PelleReason::AdminDebit,
                $label,
                $adminId,
                null,
                byAdmin: true,
            ),
            fn (PelleMovement $movement) => $this->audits->save(AdminUserActionAudit::record(
                $targetUserId,
                $adminId,
                $credit ? AdminUserActionAudit::ACTION_PELLES_CREDIT : AdminUserActionAudit::ACTION_PELLES_DEBIT,
                $this->clock->now(),
            )),
        );

        // After the commit: a notification is a side effect, never part of the ledger's unit of work.
        $this->notifier->notify($targetUserId, Notification::TYPE_PELLES_ADJUSTED, [
            'amount' => $signed,
            'kind' => $pelleKind->value,
            'reason' => $label,
        ]);

        return $recorded;
    }
}
