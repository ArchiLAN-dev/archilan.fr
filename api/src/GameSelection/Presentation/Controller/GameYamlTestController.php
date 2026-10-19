<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\GameYamlTestOutcome;
use App\GameSelection\Application\Command\StartGameYamlTest;
use App\GameSelection\Application\Query\GameYamlTestQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 9.57: a player tests the YAML of an option editor (imported or edited) with a solo generation,
 * before saving it anywhere.
 */
final readonly class GameYamlTestController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private StartGameYamlTest $startYamlTest,
        private GameYamlTestQuery $yamlTests,
    ) {
    }

    #[Route('/api/v1/games/{gameId}/yaml-test', name: 'api_game_yaml_test', methods: ['POST'])]
    public function start(Request $request, string $gameId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = json_decode($request->getContent(), true);
        $yaml = is_array($body) && is_string($body['yaml'] ?? null) ? $body['yaml'] : '';
        $start = $this->startYamlTest->start($user->getId(), $gameId, $yaml);

        return match ($start->outcome) {
            GameYamlTestOutcome::Started => new JsonResponse(['data' => ['ticket' => $start->ticket]], 202),
            GameYamlTestOutcome::InvalidYaml => $this->apiAccessGuard->errorResponse('validation_failed', 'Le YAML est vide ou dépasse 100 Ko.', 422, ['yaml' => ['Le YAML est vide ou dépasse 100 Ko.']]),
            GameYamlTestOutcome::UnknownGame => $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable, indisponible, ou sans fichier .apworld configuré.', 404),
            GameYamlTestOutcome::RequiresFile => $this->apiAccessGuard->errorResponse('required_file', 'Ce jeu a besoin d\'un fichier (une ROM) pour générer : teste-le depuis ton slot, qui permet de le joindre.', 409),
            GameYamlTestOutcome::TooManyTests => $this->apiAccessGuard->errorResponse('too_many_tests', 'Tu as lancé 5 tests en 10 minutes : attends un peu avant le suivant.', 429),
            GameYamlTestOutcome::RunnerUnavailable => $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible, le test n\'a pas été lancé. Réessaie plus tard.', 503),
        };
    }

    #[Route('/api/v1/games/{gameId}/yaml-test/{ticket}', name: 'api_game_yaml_test_result', methods: ['GET'])]
    public function result(Request $request, string $gameId, string $ticket): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->yamlTests->result($user->getId(), $gameId, $ticket);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Test introuvable ou expiré : relance-le.', 404);
        }

        return new JsonResponse(['data' => $result]);
    }
}
