<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class HelloAssoWebhookPayload
{
    public function __construct(
        public string $eventType = '',
        public HelloAssoWebhookOrderData $data = new HelloAssoWebhookOrderData(),
    ) {
    }

    #[Assert\Callback]
    public function validateOrderData(ExecutionContextInterface $context): void
    {
        if ('Order' !== $this->eventType) {
            return;
        }

        if ($this->data->id <= 0) {
            $context->buildViolation('The order ID must be a positive integer.')
                ->atPath('data.id')
                ->addViolation();
        }

        if ('' === $this->data->formSlug) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('data.formSlug')
                ->addViolation();
        }

        if ('' === $this->data->formType) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('data.formType')
                ->addViolation();
        }
    }
}
