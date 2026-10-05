<?php

declare(strict_types=1);

namespace App\Payments\Presentation\Controller;

use App\Payments\Application\Command\ManageShopAnnouncement;
use App\Payments\Application\Query\ShopAnnouncementQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 41.14: the banner announcing a promotion on the HelloAsso shop. */
final readonly class ShopAnnouncementController
{
    use RequiresAuthTrait;

    public function __construct(
        private ShopAnnouncementQuery $query,
        private ManageShopAnnouncement $manage,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    /** Public: the banner while it runs, null otherwise. */
    #[Route('/api/v1/shop/announcement', name: 'api_shop_announcement', methods: ['GET'])]
    public function running(): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->running(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/shop/announcement', name: 'api_admin_shop_announcement', methods: ['GET'])]
    public function admin(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->admin(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/shop/announcement', name: 'api_admin_shop_announcement_post', methods: ['PUT'])]
    public function post(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $message = $payload['message'] ?? null;
        $endsAt = $payload['endsAt'] ?? null;
        $end = is_string($endsAt) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $endsAt) : false;

        // Empty message or missing / past end: typed failures, mapped to HTTP by the epic-35 listener.
        $this->manage->post(is_string($message) ? $message : '', false === $end ? null : $end);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/shop/announcement', name: 'api_admin_shop_announcement_take_down', methods: ['DELETE'])]
    public function takeDown(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->takeDown();

        return new JsonResponse(null, 204);
    }
}
