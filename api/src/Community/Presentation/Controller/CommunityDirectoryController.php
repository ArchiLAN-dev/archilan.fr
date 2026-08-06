<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\CommunityDirectory;
use App\Identity\Domain\Entity\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityDirectoryController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityDirectory $directory,
    ) {
    }

    #[Route('/api/v1/community/directory', name: 'api_community_directory', methods: ['GET'])]
    public function browse(Request $request): JsonResponse
    {
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $viewerId = $viewer instanceof User ? $viewer->getId() : null;

        $sort = $request->query->getString('sort', CommunityDirectory::SORT_XP);
        $search = $request->query->getString('search');

        $result = $this->directory->browse(
            $sort,
            '' === $search ? null : $search,
            $request->query->getBoolean('friendsOnly'),
            $viewerId,
            $request->query->getInt('page', 1),
            $request->query->getInt('perPage', 0),
        );

        return new JsonResponse([
            'data' => $result['rows'],
            'meta' => ['total' => $result['total'], 'page' => $result['page'], 'perPage' => $result['perPage']],
        ]);
    }
}
