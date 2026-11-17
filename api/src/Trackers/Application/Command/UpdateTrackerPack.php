<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use App\Trackers\Domain\Service\TrackerPackShelf;
use Psr\Log\LoggerInterface;

/**
 * Changes how a pack is offered to players (story 54.2): licence vouched for, default variant, enabled, default pack
 * of its game. One unit of work: the pack and the other packs of its game whose default moved are saved together.
 */
final readonly class UpdateTrackerPack
{
    private const array MESSAGES = [
        'tracker_pack_license_unacknowledged' => 'Aucune licence détectée pour ce pack : coche « la diffusion de ce pack est autorisée » avant de le proposer aux joueurs.',
        'tracker_pack_variant_unknown' => 'Cette variante n\'existe pas dans la version du pack.',
        'tracker_pack_not_enabled' => 'Seul un pack proposé aux joueurs peut être le pack par défaut.',
    ];

    public function __construct(
        private TrackerPackRepositoryInterface $packs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NotFoundException   tracker_pack_not_found
     * @throws ValidationException when a change breaks a rule of the pack
     */
    public function update(string $packId, TrackerPackChanges $changes, string $adminId): void
    {
        $pack = $this->packs->find($packId);
        if (null === $pack) {
            throw new NotFoundException('Pack introuvable.', 'tracker_pack_not_found');
        }
        $gamePacks = $this->packs->findByGame($pack->getGameId());

        try {
            if (null !== $changes->licenseAcknowledged) {
                $pack->acknowledgeLicense($changes->licenseAcknowledged);
            }
            if ($changes->setsDefaultVariant) {
                $version = $this->packs->findVersion($pack->getCurrentVersionId());
                $known = null === $version ? [] : array_column($version->getVariants(), 'uid');
                $pack->chooseDefaultVariant($changes->defaultVariantUid, $known);
            }
            if (true === $changes->enabled) {
                TrackerPackShelf::enable($gamePacks, $pack);
            } elseif (false === $changes->enabled) {
                TrackerPackShelf::disable($gamePacks, $pack);
            }
            if (true === $changes->makeDefault) {
                TrackerPackShelf::makeDefault($gamePacks, $pack);
            }
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $message = self::MESSAGES[$code] ?? 'Ce changement est refusé.';

            throw new ValidationException($message, [], $code);
        }

        $this->packs->flush();
        $this->logger->info('tracker_pack.updated', [
            'packId' => $packId,
            'adminId' => $adminId,
            'enabled' => $pack->isEnabled(),
            'isDefault' => $pack->isDefault(),
            'defaultVariantUid' => $pack->getDefaultVariantUid(),
            'licenseAcknowledged' => $pack->isLicenseAcknowledged(),
        ]);
    }
}
