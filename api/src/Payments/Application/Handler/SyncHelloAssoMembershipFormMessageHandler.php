<?php

declare(strict_types=1);

namespace App\Payments\Application\Handler;

use App\Payments\Application\Message\SyncHelloAssoFormMessage;
use App\Payments\Application\Message\SyncHelloAssoMembershipFormMessage;
use App\Payments\Application\Support\HelloAssoConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The webhook used to be the only automatic path to a membership: a payment it missed waited for an admin
 * to click "Synchroniser HelloAsso". This runs the same sync every hour (story 22.7). The sync only
 * reports an order paid once, and a membership is never applied twice for the same order, so running it
 * often is safe.
 */
#[AsMessageHandler]
final readonly class SyncHelloAssoMembershipFormMessageHandler
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')]
        private string $membershipFormSlug,
    ) {
    }

    public function __invoke(SyncHelloAssoMembershipFormMessage $message): void
    {
        if ('' === $this->membershipFormSlug) {
            $this->logger->info('helloasso.membership_sync_skipped_not_configured');

            return;
        }

        $this->bus->dispatch(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_MEMBERSHIP, $this->membershipFormSlug));
    }
}
