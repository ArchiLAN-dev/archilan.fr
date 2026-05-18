<?php

declare(strict_types=1);

namespace App\Membership\Presentation;

use App\Membership\Application\AdminUnmatchedHelloAssoOrdersQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminUnmatchedHelloAssoOrdersController
{
    use RequiresAuthTrait;

    public function __construct(
        private AdminUnmatchedHelloAssoOrdersQuery $query,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/helloasso-orders/unmatched', name: 'api_admin_helloasso_orders_unmatched', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $orders = $this->query->execute();

        $data = array_map(static function (array $row): array {
            $rawOrderId = $row['helloasso_order_id'];
            $rawAmountCents = $row['amount_cents'];
            $rawFormSlug = $row['form_slug'];
            $rawPayerEmail = $row['payer_email'];
            $rawPayerFirstName = $row['payer_first_name'];
            $rawPayerLastName = $row['payer_last_name'];
            $rawPaidAt = $row['paid_at'];
            $rawSyncedAt = $row['synced_at'];

            return [
                'helloassoOrderId' => is_int($rawOrderId) ? $rawOrderId : (is_string($rawOrderId) ? (int) $rawOrderId : 0),
                'formSlug' => is_string($rawFormSlug) ? $rawFormSlug : '',
                'amountCents' => is_int($rawAmountCents) ? $rawAmountCents : (is_string($rawAmountCents) ? (int) $rawAmountCents : 0),
                'payerEmail' => is_string($rawPayerEmail) && '' !== $rawPayerEmail ? $rawPayerEmail : null,
                'payerFirstName' => is_string($rawPayerFirstName) && '' !== $rawPayerFirstName ? $rawPayerFirstName : null,
                'payerLastName' => is_string($rawPayerLastName) && '' !== $rawPayerLastName ? $rawPayerLastName : null,
                'paidAt' => is_string($rawPaidAt) ? $rawPaidAt : null,
                'syncedAt' => is_string($rawSyncedAt) ? $rawSyncedAt : null,
            ];
        }, $orders);

        return new JsonResponse(['data' => $data, 'meta' => []]);
    }
}
