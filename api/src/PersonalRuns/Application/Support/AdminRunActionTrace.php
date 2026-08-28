<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Support;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Identity\Domain\Repository\AdminUserActionAuditRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use Psr\Clock\ClockInterface;

/**
 * Trace d'un réglage appliqué par un administrateur à la partie privée d'un autre membre
 * (story 16.19).
 *
 * Réutilise `admin_user_action_audit` plutôt que d'ouvrir une table : la story 36.6 l'a créée pour
 * exactement ce motif - un geste d'admin sur ce qui appartient à un membre, invisible autrement - et
 * `DbalAdminUserActivityQuery` la remonte déjà dans la fiche du membre. La cible est donc le
 * **propriétaire de la partie**, parce que c'est là qu'on ira chercher qui a touché à sa run.
 *
 * Un propriétaire qui agit sur sa propre partie n'écrit rien : la trace existe pour distinguer
 * l'intervention extérieure, pas pour journaliser l'usage normal. Le test se fait ici, en un seul
 * endroit, plutôt que dans chacun des appelants.
 */
final readonly class AdminRunActionTrace
{
    public function __construct(
        private AdminUserActionAuditRepositoryInterface $audits,
        private ClockInterface $clock,
    ) {
    }

    public function record(Run $run, string $callerId, string $action): void
    {
        if ($run->isOwnedBy($callerId)) {
            return;
        }

        $this->audits->save(AdminUserActionAudit::record(
            $run->getOwnerId(),
            $callerId,
            $action,
            $this->clock->now(),
        ));
    }
}
