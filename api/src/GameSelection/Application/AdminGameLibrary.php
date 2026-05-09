<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\ValidationErrors;
use App\Sessions\Infrastructure\RunnerGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminGameLibrary
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private RunnerGatewayInterface $runnerGateway,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('game')
            ->from(ArchipelagoGame::class, 'game')
            ->orderBy('game.name', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return array_map(fn (ArchipelagoGame $game): array => $this->payload($game), $games);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $gameId): ?array
    {
        $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);

        return $game instanceof ArchipelagoGame ? $this->detailPayload($game) : null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function create(array $input): array
    {
        $parsed = $this->parse($input);
        $errors = $this->validate($parsed);
        $this->validateUniqueSlug($parsed['slug'], null, $errors);

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $game = ArchipelagoGame::create(
            $parsed['name'],
            $parsed['slug'],
            $parsed['description'],
            $parsed['coverImageUrl'],
            $parsed['coverImageAlt'],
            $parsed['coverImageCredit'],
            $parsed['availability'],
            new \DateTimeImmutable(),
        );

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->logger->info('game.created', ['gameId' => $game->getId(), 'name' => $game->getName()]);

        return ['game' => $this->payload($game), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function update(string $gameId, array $input): array
    {
        $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);

        if (!$game instanceof ArchipelagoGame) {
            return ['found' => false, 'errors' => []];
        }

        $parsed = $this->parse($input);
        $errors = $this->validate($parsed);
        $this->validateUniqueSlug($parsed['slug'], $game->getId(), $errors);

        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        $game->update(
            $parsed['name'],
            $parsed['slug'],
            $parsed['description'],
            $parsed['coverImageUrl'],
            $parsed['coverImageAlt'],
            $parsed['coverImageCredit'],
            $parsed['availability'],
            new \DateTimeImmutable(),
        );
        $this->entityManager->flush();

        $this->logger->info('game.updated', ['gameId' => $gameId]);

        return ['found' => true, 'game' => $this->payload($game), 'errors' => []];
    }

    /**
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function configureApworld(string $gameId, string $fileContents, string $filename): array
    {
        $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);

        if (!$game instanceof ArchipelagoGame) {
            return ['found' => false, 'errors' => []];
        }

        $errors = new ValidationErrors();

        if ('apworld' !== pathinfo($filename, PATHINFO_EXTENSION)) {
            $errors->add('file', 'Le fichier doit avoir l\'extension .apworld.');
        }

        if ('' === $fileContents) {
            $errors->add('file', 'Le fichier est vide.');
        }

        if ([] !== $errors->toArray()) {
            return ['found' => true, 'errors' => $errors->toArray()];
        }

        $result = $this->runnerGateway->uploadApworld($fileContents, $filename);

        if (isset($result['error'])) {
            $detail = is_string($result['detail'] ?? null) ? $result['detail'] : null;
            $message = match ($result['error']) {
                'runner_unavailable' => 'Le runner est indisponible.',
                'invalid_file' => 'Le fichier n\'est pas un .apworld valide.',
                'invalid_apworld' => $detail ?? 'Le fichier .apworld est invalide (archipelago.json manquant ou corrompu).',
                'template_timeout' => 'La génération du template a expiré - le runner est peut-être surchargé.',
                'template_failed' => 'ArchipelagoGenerate a échoué'.($detail ? " : {$detail}" : '.'),
                'archigenerate_not_found' => 'ArchipelagoGenerate est introuvable dans le runner. Configurez ARCHIPELAGO_GENERATE_CMD.',
                default => $detail ?? 'Erreur runner : '.(is_string($result['error']) ? $result['error'] : ''),
            };
            $this->logger->error('runner.apworld_upload_failed', ['gameId' => $gameId, 'error' => $result['error'], 'detail' => $detail]);

            return ['found' => true, 'errors' => ['file' => [$message]]];
        }

        $storageKey = is_string($result['storageKey'] ?? null) ? $result['storageKey'] : '';
        $hash = is_string($result['hash'] ?? null) ? $result['hash'] : '';
        $archipelagoGameName = is_string($result['archipelagoGameName'] ?? null) ? $result['archipelagoGameName'] : '';
        $defaultYaml = is_string($result['defaultYaml'] ?? null) ? $result['defaultYaml'] : '';

        if ('' === $storageKey || '' === $hash || '' === $archipelagoGameName) {
            return ['found' => true, 'errors' => ['file' => ['Le runner est indisponible ou le fichier .apworld est invalide.']]];
        }

        $game->configureApworld($storageKey, $hash, $archipelagoGameName, $defaultYaml, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->logger->info('game.apworld_configured', ['gameId' => $gameId, 'hash' => $hash, 'archipelagoGameName' => $archipelagoGameName]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @return array{found: bool, errors: array<string, list<string>>}
     */
    public function remove(string $gameId): array
    {
        $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);

        if (!$game instanceof ArchipelagoGame) {
            return ['found' => false, 'errors' => []];
        }

        if ($this->usageCount($game) > 0) {
            return ['found' => true, 'errors' => ['game' => ['Ce jeu est déjà utilisé et ne peut pas être supprimé.']]];
        }

        $this->entityManager->remove($game);
        $this->entityManager->flush();

        $this->logger->info('game.removed', ['gameId' => $gameId]);

        return ['found' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, slug: string, description: string, coverImageUrl: ?string, coverImageAlt: string, coverImageCredit: string, availability: string}
     */
    private function parse(array $input): array
    {
        $rawCoverImageUrl = $input['coverImageUrl'] ?? null;

        return [
            'name' => is_string($input['name'] ?? null) ? trim($input['name']) : '',
            'slug' => is_string($input['slug'] ?? null) ? ArchipelagoGame::normalizeSlug($input['slug']) : '',
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : '',
            'coverImageUrl' => is_string($rawCoverImageUrl) && '' !== trim($rawCoverImageUrl) ? trim($rawCoverImageUrl) : null,
            'coverImageAlt' => is_string($input['coverImageAlt'] ?? null) ? trim($input['coverImageAlt']) : '',
            'coverImageCredit' => is_string($input['coverImageCredit'] ?? null) ? trim($input['coverImageCredit']) : '',
            'availability' => is_string($input['availability'] ?? null) ? trim($input['availability']) : '',
        ];
    }

    /**
     * @param array{name: string, slug: string, description: string, coverImageUrl: ?string, coverImageAlt: string, coverImageCredit: string, availability: string} $input
     *
     * @return array<string, list<string>>
     */
    private function validate(array $input): array
    {
        $errors = new ValidationErrors();

        foreach (['name' => 'Le nom est requis.', 'slug' => 'Le slug est requis.', 'description' => 'La description est requise.'] as $field => $message) {
            if ('' === $input[$field]) {
                $errors->add($field, $message);
            }
        }

        if ('' !== $input['slug'] && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $input['slug'])) {
            $errors->add('slug', 'Le slug doit contenir seulement des minuscules, chiffres et tirets.');
        }

        if (!in_array($input['availability'], ArchipelagoGame::supportedAvailabilities(), true)) {
            $errors->add('availability', 'État de disponibilité invalide.');
        }

        return $errors->toArray();
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function validateUniqueSlug(string $slug, ?string $currentGameId, array &$errors): void
    {
        if ('' === $slug) {
            return;
        }

        $existing = $this->entityManager->getRepository(ArchipelagoGame::class)->findOneBy(['slug' => $slug]);

        if ($existing instanceof ArchipelagoGame && $existing->getId() !== $currentGameId) {
            $errors['slug'][] = 'Ce slug est déjà utilisé.';
        }
    }

    private function usageCount(ArchipelagoGame $game): int
    {
        // Registration/game usage is introduced by later stories. Keep the boundary explicit.
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ArchipelagoGame $game): array
    {
        return [
            'id' => $game->getId(),
            'name' => $game->getName(),
            'slug' => $game->getSlug(),
            'description' => $game->getDescription(),
            'coverImageUrl' => $game->getCoverImageUrl(),
            'coverImageAlt' => $game->getCoverImageAlt(),
            'coverImageCredit' => $game->getCoverImageCredit(),
            'availability' => $game->getAvailability(),
            'archipelagoGameName' => $game->getArchipelagoGameName(),
            'isYamlReady' => $game->isYamlReady(),
            'isApworldReady' => $game->isApworldReady(),
            'apworldHash' => $game->getApworldHash(),
            'apworldUploadedAt' => $game->getApworldUploadedAt()?->format(\DateTimeInterface::ATOM),
            'usageCount' => $this->usageCount($game),
            'createdAt' => $game->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $game->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(ArchipelagoGame $game): array
    {
        return array_merge($this->payload($game), [
            'defaultYaml' => $game->getDefaultYaml(),
        ]);
    }
}
