<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

use App\Payments\Application\HandleHelloAssoWebhook;
use App\Payments\Application\HelloAssoConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HelloAssoWebhookController
{
    public function __construct(
        private HandleHelloAssoWebhook $handleWebhook,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/webhooks/helloasso', name: 'api_webhooks_helloasso', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] HelloAssoWebhookPayload $payload,
    ): JsonResponse {
        $this->logger->info('helloasso.webhook.received', [
            'eventType' => $payload->eventType,
            'orderId' => $payload->data->id ?: null,
            'formType' => $payload->data->formType ?: null,
            'formSlug' => $payload->data->formSlug ?: null,
        ]);

        if ('Order' !== $payload->eventType) {
            $this->logger->info('helloasso.webhook.ignored', [
                'reason' => 'non_order_event',
                'eventType' => $payload->eventType,
            ]);

            return new JsonResponse(null, Response::HTTP_OK);
        }

        $payerEmail = '' !== $payload->data->payer->email ? $payload->data->payer->email : null;
        $paidAt = null;
        if ('' !== $payload->data->date) {
            try {
                $paidAt = new \DateTimeImmutable($payload->data->date);
            } catch (\Exception) {
            }
        }

        $this->handleWebhook->handle(
            $payload->data->id,
            HelloAssoConfig::fromApiFormType($payload->data->formType),
            $payload->data->formSlug,
            $payerEmail,
            $paidAt,
        );

        return new JsonResponse(null, Response::HTTP_OK);
    }
}
