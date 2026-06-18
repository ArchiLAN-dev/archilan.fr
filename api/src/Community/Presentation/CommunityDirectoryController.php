<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityDirectory;
use App\Identity\Domain\User;
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

        $mode = $request->query->getString('mode', CommunityDirectory::MODE_TOP);
        $search = $request->query->getString('search');

        $result = $this->directory->browse(
            $mode,
            '' === $search ? null : $search,
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
