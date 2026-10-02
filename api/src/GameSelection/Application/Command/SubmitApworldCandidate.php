<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns an apworld file into a candidate in test (story 38.6), for every way a new version arrives:
 * a file uploaded by an admin, a GitHub import, the nightly automatic update.
 *
 * Uploading is not serving. The file goes to the orchestrator - which starts the solo test generation
 * on its own - and to MinIO, and becomes a candidate. The game keeps serving its current apworld until
 * the verdict promotes the candidate (DecideApworldCandidates). A candidate still in test for the same
 * game is superseded: one candidate at a time, the latest wins.
 */
final readonly class SubmitApworldCandidate
{
    public function __construct(
        private GameRepositoryInterface $games,
        private ApworldCandidateRepositoryInterface $candidates,
        private RunnerGatewayInterface $runnerGateway,
        private MinioStorageInterface $minioStorage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $minioApworldsBucket,
    ) {
    }

    /**
     * @param bool $holdForApproval story 38.14: a passed test waits for the admin instead of putting the version online
     */
    public function submit(string $gameId, string $fileContents, string $filename, ?string $versionTag, ApworldCandidateOrigin $origin, ?string $submittedBy, bool $holdForApproval = false): ApworldCandidateSubmission
    {
        if (!$this->games->findById($gameId) instanceof Game) {
            return new ApworldCandidateSubmission(false, null);
        }

        $errors = [];
        if ('apworld' !== pathinfo($filename, \PATHINFO_EXTENSION)) {
            $errors[] = 'Le fichier doit avoir l\'extension .apworld.';
        }
        if ('' === $fileContents) {
            $errors[] = 'Le fichier est vide.';
        }
        if ([] !== $errors) {
            return new ApworldCandidateSubmission(true, null, $errors);
        }

        $result = $this->runnerGateway->uploadApworld($fileContents, $filename);
        if (isset($result['error'])) {
            $this->logger->error('runner.apworld_upload_failed', ['gameId' => $gameId, 'error' => $result['error'], 'detail' => $result['detail'] ?? null]);

            return new ApworldCandidateSubmission(true, null, [self::uploadErrorMessage($result['error'], $result['detail'] ?? null)]);
        }

        $storageKey = is_string($result['storageKey'] ?? null) ? $result['storageKey'] : '';
        $hash = is_string($result['hash'] ?? null) ? $result['hash'] : '';
        $archipelagoGameName = is_string($result['archipelagoGameName'] ?? null) ? $result['archipelagoGameName'] : '';
        $defaultYaml = is_string($result['defaultYaml'] ?? null) ? $result['defaultYaml'] : '';
        if ('' === $storageKey || '' === $hash || '' === $archipelagoGameName) {
            return new ApworldCandidateSubmission(true, null, ['Le runner est indisponible ou le fichier .apworld est invalide.']);
        }

        $minioKey = $hash.'.apworld';
        try {
            if (!$this->minioStorage->exists($this->minioApworldsBucket, $minioKey)) {
                $this->minioStorage->upload($this->minioApworldsBucket, $minioKey, $fileContents);
            }
        } catch (\Throwable $e) {
            $this->logger->error('minio.apworld_upload_failed', ['gameId' => $gameId, 'hash' => $hash, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return new ApworldCandidateSubmission(true, null, ['storage_unavailable']);
        }

        $now = $this->clock->now();
        $this->candidates->findPendingForGame($gameId)?->supersede($now);

        $candidate = ApworldCandidate::submit(
            bin2hex(random_bytes(16)),
            $gameId,
            $hash,
            $storageKey,
            $minioKey,
            $defaultYaml,
            $archipelagoGameName,
            $versionTag,
            $origin,
            $submittedBy,
            $now,
            $holdForApproval,
        );
        $this->candidates->save($candidate);
        $this->candidates->flush();

        $this->logger->info('game.apworld_candidate_submitted', [
            'gameId' => $gameId,
            'candidateId' => $candidate->getId(),
            'hash' => $hash,
            'versionTag' => $versionTag,
            'origin' => $origin->value,
        ]);

        return new ApworldCandidateSubmission(true, $candidate->getId());
    }

    private static function uploadErrorMessage(mixed $error, mixed $detail): string
    {
        $detail = is_string($detail) && '' !== $detail ? $detail : null;

        return match ($error) {
            'runner_unavailable' => 'Le runner est indisponible.',
            'invalid_file' => 'Le fichier n\'est pas un .apworld valide.',
            'invalid_apworld' => $detail ?? 'Le fichier .apworld est invalide (archipelago.json manquant ou corrompu).',
            'template_timeout' => 'La génération du template a expiré - le runner est peut-être surchargé.',
            'template_failed' => 'ArchipelagoGenerate a échoué'.(null !== $detail ? ' : '.$detail : '.'),
            'archigenerate_not_found' => 'ArchipelagoGenerate est introuvable dans le runner. Configurez ARCHIPELAGO_GENERATE_CMD.',
            default => $detail ?? 'Erreur runner : '.(is_string($error) ? $error : ''),
        };
    }
}
