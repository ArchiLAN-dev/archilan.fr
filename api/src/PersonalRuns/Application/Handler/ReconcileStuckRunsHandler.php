<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\PersonalRuns\Application\Message\ReconcileStuckRunsMessage;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Backstop côté run (story 17.14) : ramène une run coincée dans un statut transitoire (starting /
 * stopping / restarting) au-delà de son seuil vers l'état réel de sa session liée. Couvre le cas où la
 * session a déjà été résolue (par son propre watchdog) mais où le webhook qui devait avancer la run
 * s'est perdu - typiquement un `stopping` qui ne retombe jamais en `idle`.
 *
 *  - session running                              → run active (markRunning) ;
 *  - session résolue (idle/stopped/crashed/failed/finished) ou absente :
 *      run starting  → draft (la génération a échoué, le proprio peut refaire) ;
 *      run stopping/restarting → idle (markStopped) ;
 *  - session encore transitoire → on attend (son watchdog la résoudra, la run suivra au prochain tour).
 */
#[AsMessageHandler]
final readonly class ReconcileStuckRunsHandler
{
    private const RESOLVED_SESSION_STATUSES = [
        Session::STATUS_IDLE,
        Session::STATUS_STOPPED,
        Session::STATUS_CRASHED,
        Session::STATUS_FAILED,
        Session::STATUS_FINISHED,
    ];

    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionRepositoryInterface $sessions,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileStuckRunsMessage $message): void
    {
        $now = new \DateTimeImmutable();

        $candidates = $this->runs->findByStatuses(Run::STUCK_STATUSES);

        $reconciled = 0;

        foreach ($candidates as $run) {
            if (!$run->isStuck($now)) {
                continue;
            }

            $from = $run->getStatus();
            $sessionId = $run->getSessionId();
            $session = null !== $sessionId ? $this->sessions->findById($sessionId) : null;
            $sessionStatus = $session instanceof Session ? $session->getStatus() : null;

            if ($session instanceof Session && Session::STATUS_RUNNING === $sessionStatus) {
                $run->markRunning($session->getHost() ?? '', $session->getPort() ?? 0, $now, $session->getPassword());
                $to = Run::STATUS_ACTIVE;
            } elseif (null === $sessionStatus || in_array($sessionStatus, self::RESOLVED_SESSION_STATUSES, true)) {
                if (Run::STATUS_STARTING === $from) {
                    $run->resetAfterValidationFailure($now);
                    $to = Run::STATUS_DRAFT;
                } else {
                    $run->markStopped($now);
                    $to = Run::STATUS_IDLE;
                }
            } else {
                // Session still transitional - leave it to the session watchdog, retry next pass.
                continue;
            }

            $this->logger->warning('personal_run.reconcile.stuck', [
                'runId' => $run->getId(),
                'from' => $from,
                'to' => $to,
                'sessionStatus' => $sessionStatus,
            ]);

            ++$reconciled;
        }

        if ($reconciled > 0) {
            $this->runs->flush();
            $this->logger->info('personal_run.reconcile.done', ['reconciled' => $reconciled]);
        }
    }
}
