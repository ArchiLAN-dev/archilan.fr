<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\CatalogSync\Application\Query\ApworldUpdateCheckRunView;
use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;
use App\CatalogSync\Domain\Repository\ApworldUpdateCheckRunRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 38.18: the catalogue page's « Vérifier les mises à jour » button. It records a pass and queues it for
 * the worker instead of checking in the request: 556 games take minutes, and PHP cut the request at 30 seconds,
 * losing everything. The queued pass only checks; submitting stays the nightly run's job (story 38.6).
 *
 * At most one pass is active at a time, the night's included. One that stopped moving long ago died with its
 * worker: it is closed as failed so it does not block the button forever.
 */
final readonly class QueueApworldUpdateCheck
{
    public function __construct(
        private ApworldUpdateCheckRunRepositoryInterface $runs,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function queue(): ApworldUpdateCheckQueued
    {
        $now = $this->clock->now();

        $active = $this->runs->findActive();
        if (null !== $active && $active->isStale($now)) {
            $active->fail('Interrompue : la vérification ne progressait plus.', $now);
            $this->runs->save($active);
        }

        $run = ApworldUpdateCheckRun::queue(bin2hex(random_bytes(16)), ApworldUpdateCheckRun::TRIGGER_BUTTON, $now);
        if (!$this->runs->claim($run)) {
            $current = $this->runs->findActive();

            return new ApworldUpdateCheckQueued(false, null === $current ? null : ApworldUpdateCheckRunView::of($current));
        }

        $this->messageBus->dispatch(new CheckApworldUpdatesMessage($run->getId(), submit: false));

        return new ApworldUpdateCheckQueued(true, ApworldUpdateCheckRunView::of($run));
    }
}
