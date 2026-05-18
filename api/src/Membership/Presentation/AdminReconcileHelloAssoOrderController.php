<?php

declare(strict_types=1);

namespace App\Membership\Presentation;

use App\Membership\Application\AdminReconcileHelloAssoOrder;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminReconcileHelloAssoOrderController
{
    use RequiresAuthTrait;

    public function __construct(
        private AdminReconcileHelloAssoOrder $command,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/helloasso-orders/{orderId}/reconcile', name: 'api_admin_helloasso_order_reconcile', methods: ['POST'])]
    public function __invoke(Request $request, int $orderId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = json_decode($request->getContent(), true);
        $userId = is_array($body) && is_string($body['userId'] ?? null) ? $body['userId'] : '';

        if ('' === $userId) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le champ userId est requis.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->command->reconcile($orderId, $userId);
        } catch (\RuntimeException $e) {
            return $this->apiAccessGuard->errorResponse('reconcile_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(null, Response::HTTP_OK);
    }
}
