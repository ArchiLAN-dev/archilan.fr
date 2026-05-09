<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminGameLibraryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousAndLambdaCannotManageGameLibrary(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesEmptyGameLibrary(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertSame([], $response['data']);
    }

    public function testAdminCreatesUpdatesListsAndDeletesGame(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('The Legend of Zelda: Ocarina of Time', $response['data']['name']);
        self::assertSame('ocarina-of-time', $response['data']['slug']);
        self::assertSame(0, $response['data']['usageCount']);

        $gameId = $response['data']['id'];
        self::assertIsString($gameId);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s', $gameId), [
            ...$this->validPayload(),
            'name' => 'Ocarina of Time AP',
            'slug' => 'oot-ap',
            'availability' => ArchipelagoGame::AVAILABILITY_EXPERIMENTAL,
        ]);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Ocarina of Time AP', $response['data']['name']);
        self::assertSame('oot-ap', $response['data']['slug']);
        self::assertSame(ArchipelagoGame::AVAILABILITY_EXPERIMENTAL, $response['data']['availability']);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data']);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/games/%s', $gameId));
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertSame([], $list['data']);
    }

    public function testValidationErrorsAndDuplicateSlugAreReturned(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/games', [
            'name' => '',
            'slug' => 'Invalid Slug!',
            'description' => '',
            'coverImageAlt' => '',
            'coverImageCredit' => '',
            'availability' => 'unknown',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        foreach (['name', 'slug', 'description', 'availability'] as $field) {
            self::assertArrayHasKey($field, $response['error']['details']);
        }

        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('slug', $response['error']['details']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'The Legend of Zelda: Ocarina of Time',
            'slug' => 'ocarina-of-time',
            'description' => 'Un classique compatible Archipelago avec progression multiworld.',
            'coverImageAlt' => 'Logo Ocarina of Time',
            'coverImageCredit' => 'Nintendo',
            'availability' => ArchipelagoGame::AVAILABILITY_AVAILABLE,
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
