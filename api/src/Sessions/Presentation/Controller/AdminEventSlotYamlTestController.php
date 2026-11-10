<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\GameSelection\Application\Query\YamlTestResultQuery;
use App\Sessions\Application\Command\EventSlotYamlTestOutcome;
use App\Sessions\Application\Command\StartEventSlotYamlTest;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiredGenerationFilesRequest;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 11.11: an admin tests the config of one slot of an event alone, then reads the verdict.
 */
final readonly class AdminEventSlotYamlTestController
{
    use RequiresAuthTrait;

    private const string FILE_REFUSED = 'Le fichier n\'a pas pu être transmis. Réessaie dans un instant.';

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private StartEventSlotYamlTest $startYamlTest,
        private YamlTestResultQuery $yamlTests,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/slots/{slotId}/yaml-test', name: 'api_admin_event_slot_yaml_test', methods: ['POST'])]
    public function start(Request $request, string $eventId, string $registrationId, string $slotId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        // The file a game reads at generation (a ROM) travels as multipart `requiredFiles[<setting>]` (story 44.7).
        $provided = RequiredGenerationFilesRequest::read($request);
        if ([] !== $provided['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', $provided['errors'][0], 422, ['requiredFiles' => $provided['errors']]);
        }

        $start = $this->startYamlTest->start($eventId, $registrationId, $slotId, $provided['files']);

        return match ($start->outcome) {
            EventSlotYamlTestOutcome::Started => new JsonResponse(['data' => ['jobId' => $start->jobId]], 202),
            EventSlotYamlTestOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Slot introuvable dans cet événement, ou jeu sans apworld prêt.', 404),
            EventSlotYamlTestOutcome::NoYaml => $this->apiAccessGuard->errorResponse('validation_failed', 'Ce slot n\'a aucun YAML à tester.', 422, ['playerYaml' => ['Ce slot n\'a aucun YAML à tester.']]),
            // The error envelope of ApiAccessGuard, its details carrying the files to ask for rather than messages.
            EventSlotYamlTestOutcome::RequiresFile => new JsonResponse(['error' => [
                'code' => 'required_file',
                'message' => 'Ce jeu lit un fichier pendant la génération : joins-le pour tester ce slot.',
                'details' => ['requiredFiles' => $start->requiredFiles],
            ]], 409),
            EventSlotYamlTestOutcome::FileRefused => $this->apiAccessGuard->errorResponse('required_file_refused', self::FILE_REFUSED, 422, ['requiredFiles' => [self::FILE_REFUSED]]),
            EventSlotYamlTestOutcome::RunnerUnavailable => $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible, le test n\'a pas été lancé. Réessaie plus tard.', 503),
        };
    }

    #[Route('/api/v1/admin/events/{eventId}/yaml-tests/{jobId}', name: 'api_admin_event_slot_yaml_test_result', methods: ['GET'])]
    public function result(Request $request, string $eventId, string $jobId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->yamlTests->result($jobId);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Test introuvable ou expiré : relance-le.', 404);
        }

        return new JsonResponse(['data' => $result]);
    }
}
