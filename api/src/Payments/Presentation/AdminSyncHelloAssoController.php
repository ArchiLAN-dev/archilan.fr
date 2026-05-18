<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

use App\Payments\Application\HelloAssoConfig;
use App\Payments\Application\SyncHelloAssoFormMessage;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminSyncHelloAssoController
{
    use RequiresAuthTrait;

    public function __construct(
        private MessageBusInterface $bus,
        private ApiAccessGuard $apiAccessGuard,
        private LoggerInterface $logger,
        #[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')]
        private string $membershipFormSlug,
    ) {
    }

    #[Route('/api/v1/admin/helloasso/sync', name: 'api_admin_helloasso_sync', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        if ('' === $this->membershipFormSlug) {
            return $this->apiAccessGuard->errorResponse(
                'not_configured',
                'HELLOASSO_MEMBERSHIP_FORM_SLUG n\'est pas configuré.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->bus->dispatch(new SyncHelloAssoFormMessage(
            HelloAssoConfig::FORM_TYPE_MEMBERSHIP,
            $this->membershipFormSlug,
        ));

        $this->logger->info('helloasso.admin_sync_triggered', [
            'adminId' => $admin->getId(),
            'formSlug' => $this->membershipFormSlug,
        ]);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }
}
