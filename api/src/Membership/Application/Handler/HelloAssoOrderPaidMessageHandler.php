<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Membership\Application\ProcessHelloAssoMembershipPaymentInterface;
use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class HelloAssoOrderPaidMessageHandler
{
    public function __construct(
        private ProcessHelloAssoMembershipPaymentInterface $service,
        #[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')]
        private string $membershipFormSlug,
    ) {
    }

    public function __invoke(HelloAssoOrderPaidMessage $message): void
    {
        if ($message->formSlug !== $this->membershipFormSlug) {
            return;
        }

        $this->service->process($message->helloassoOrderId, $message->payerEmail, $message->paidAt);
    }
}
