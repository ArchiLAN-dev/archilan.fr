<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // Build the FULL schema once per test (every mapped entity), so individual
        // test classes never have to declare a partial entity subset. Subsets were
        // fragile: on SQLite they relied on tables leaking between classes; on
        // Postgres that leakage is gone and incomplete subsets fail with missing-table
        // or FK errors. A clean full schema removes that whole class of problem.
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $connection = $this->entityManager->getConnection();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // Fast, FK-safe wipe; SQLite has no schemas so fall back to SchemaTool.
            $connection->executeStatement('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        } else {
            $schemaTool->dropSchema($metadata);
        }

        if ([] !== $metadata) {
            $schemaTool->createSchema($metadata);
        }
    }

    protected function loginAs(User $user): void
    {
        $signer = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $signer);
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $signer->sign($user->getId())),
        );
    }

    /** @return array<mixed> */
    protected function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $roles
     */
    protected function createUser(
        string $email,
        array $roles = ['ROLE_USER'],
        string $displayName = 'Test User',
        ?string $slug = null,
        ?\DateTimeImmutable $emailVerifiedAt = null,
        bool $emailVerified = true,
    ): User {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $resolvedEmailVerifiedAt = $emailVerified ? ($emailVerifiedAt ?? $now) : null;
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            $displayName,
            'test-password-hash',
            $roles,
            $now, $now, $now,
            slug: $slug,
            emailVerifiedAt: $resolvedEmailVerifiedAt,
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param list<array{gameId: string}>                                         $gameSelectionConfig
     * @param list<string|array{source: string, url?: string, key?: string}>|null $photoGallery
     */
    protected function createEvent(
        string $title,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        int $capacity = 50,
        bool $published = false,
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        bool $isPublic = true,
        ?string $coverImageUrl = null,
        ?array $photoGallery = null,
        ?\DateTimeImmutable $registrationOpensAt = null,
        ?\DateTimeImmutable $registrationClosesAt = null,
        ?int $gameSelectionMaxPerRegistrant = null,
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = Event::draft(
            $title,
            'Description.',
            $startsAt,
            $endsAt,
            'Test Venue',
            $capacity,
            $registrationOpensAt ?? new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            $registrationClosesAt ?? new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
            $isPublic,
            $now,
            $coverImageUrl,
            $photoGallery,
        );
        if ($gameSelectionEnabled || [] !== $gameSelectionConfig) {
            $event->configureGameSelection($gameSelectionEnabled, $gameSelectionConfig, $now, $gameSelectionMaxPerRegistrant);
        }
        if ($published) {
            $event->transitionTo(Event::STATUS_PUBLISHED, $now);
        }
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    protected function createGame(
        string $name,
        string $slug,
        string $availability = Game::AVAILABILITY_AVAILABLE,
    ): Game {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $game = Game::create(
            $name,
            $slug,
            'Description.',
            null,
            'Alt',
            'Publisher',
            $availability,
            $now,
        );
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    /**
     * Applies status transitions from STATUS_DRAFT up to $targetStatus.
     * Does NOT flush - caller must flush after any additional mutations.
     */
    protected function transitionEventTo(Event $event, string $targetStatus, \DateTimeImmutable $now): void
    {
        $chain = [Event::STATUS_PUBLISHED, Event::STATUS_IN_PROGRESS, Event::STATUS_COMPLETED];
        $position = array_search($targetStatus, $chain, true);
        if (false !== $position) {
            for ($i = 0; $i <= $position; ++$i) {
                $event->transitionTo($chain[$i], $now);
            }
        }
    }

    /**
     * @param list<string> $selectedGameIds
     */
    protected function createRegistration(
        string $eventId,
        string $userId,
        string $status = Registration::STATUS_RESERVED,
        array $selectedGameIds = [],
    ): Registration {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $slots = array_map(
            static fn (string $gameId, int $idx): array => [
                'slotId' => bin2hex(random_bytes(8)),
                'gameId' => $gameId,
                'slotOrder' => $idx + 1,
            ],
            $selectedGameIds,
            array_keys($selectedGameIds),
        );
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            $status,
            $now,
            $now,
            $slots,
        );
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }
}
