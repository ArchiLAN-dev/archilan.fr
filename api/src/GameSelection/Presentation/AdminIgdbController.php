<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Infrastructure\IgdbAuthException;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminIgdbController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private IgdbHttpClientInterface $igdbHttpClient,
    ) {
    }

    #[Route('/api/v1/admin/igdb/search', name: 'api_game_selection_admin_igdb_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $q = trim($request->query->getString('q'));

        if ('' === $q) {
            return $this->apiAccessGuard->errorResponse('igdb_query_required', 'Le paramètre "q" est obligatoire.', 422);
        }

        $offset = max(0, $request->query->getInt('offset', 0));
        $limit = 10;

        try {
            $results = $this->igdbHttpClient->searchGames($q, $limit, $offset);
        } catch (IgdbAuthException) {
            return $this->apiAccessGuard->errorResponse('igdb_auth_failed', "L'authentification IGDB a échoué.", 502);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('igdb_search_failed', 'La recherche IGDB a échoué.', 502);
        }

        return new JsonResponse(['data' => $results, 'meta' => ['hasMore' => count($results) === $limit, 'offset' => $offset]]);
    }
}
