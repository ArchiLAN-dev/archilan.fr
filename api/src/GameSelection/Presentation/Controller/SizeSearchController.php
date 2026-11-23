<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\SizeSearchOutcome;
use App\GameSelection\Application\Command\StartSizeSearch;
use App\GameSelection\Application\Query\SizeSearchQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 56.7: a player asks for a size option value that brings their world back to the target, then follows the
 * search. Nothing is applied: the editor offers the proposal.
 */
final readonly class SizeSearchController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private StartSizeSearch $startSearch,
        private SizeSearchQuery $searches,
    ) {
    }

    #[Route('/api/v1/games/{gameId}/size-search', name: 'api_game_size_search', methods: ['POST'])]
    public function start(Request $request, string $gameId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $yaml = is_string($body['yaml'] ?? null) ? $body['yaml'] : '';
        $eventId = is_string($body['eventId'] ?? null) && '' !== $body['eventId'] ? $body['eventId'] : null;
        $runId = is_string($body['runId'] ?? null) && '' !== $body['runId'] ? $body['runId'] : null;
        $start = $this->startSearch->start($user->getId(), $gameId, $yaml, $eventId, $runId);

        return match ($start->outcome) {
            SizeSearchOutcome::Started => new JsonResponse(['data' => ['searchId' => $start->searchId]], 202),
            SizeSearchOutcome::InvalidYaml => $this->apiAccessGuard->errorResponse('validation_failed', 'Le YAML est vide ou dépasse 100 Ko.', 422, ['yaml' => ['Le YAML est vide ou dépasse 100 Ko.']]),
            SizeSearchOutcome::UnknownGame => $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable, indisponible, ou sans fichier .apworld configuré.', 404),
            SizeSearchOutcome::RequiresFile => $this->apiAccessGuard->errorResponse('required_file', 'Ce jeu a besoin d\'un fichier (une ROM) pour générer : la recherche automatique ne peut pas le tester.', 409),
            SizeSearchOutcome::NoSizeOptions => $this->apiAccessGuard->errorResponse('no_size_options', 'Aucune option de taille n\'est désignée pour ce jeu.', 409),
            SizeSearchOutcome::NoTarget => $this->apiAccessGuard->errorResponse('no_target', 'Il n\'y a pas de durée cible à viser.', 409),
            SizeSearchOutcome::UnknownPace => $this->apiAccessGuard->errorResponse('unknown_pace', 'Le rythme de ce jeu est inconnu : sa durée ne peut pas être estimée.', 409),
        };
    }

    #[Route('/api/v1/games/{gameId}/size-search/{searchId}', name: 'api_game_size_search_state', methods: ['GET'])]
    public function state(Request $request, string $gameId, string $searchId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $search = $this->searches->forPlayer($user->getId(), $gameId, $searchId);
        if (null === $search) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Recherche introuvable.', 404);
        }

        return new JsonResponse(['data' => $search]);
    }
}
