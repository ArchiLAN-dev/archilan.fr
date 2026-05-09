<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\PrivacyRightsRequest;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class PrivacyRightsRequestTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(PrivacyRightsRequest::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedPrivacyRequestIsRejected(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/account/privacy-requests', [
            'rightType' => PrivacyRightsRequest::RIGHT_ACCESS,
        ]);

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testAuthenticatedUserCanSubmitEverySupportedRight(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');
        $this->loginAs($user);

        foreach (PrivacyRightsRequest::supportedRights() as $rightType) {
            $this->client->jsonRequest('POST', '/api/v1/account/privacy-requests', [
                'rightType' => $rightType,
                'details' => 'Merci de traiter cette demande.',
            ]);

            self::assertResponseStatusCodeSame(201);
            $response = $this->decodedJsonResponse();
            self::assertIsArray($response['data']);
            self::assertSame($rightType, $response['data']['rightType']);
            self::assertSame(PrivacyRightsRequest::STATUS_RECEIVED, $response['data']['status']);
            self::assertSame(PrivacyRightsRequest::HANDLING_MANUAL_REVIEW, $response['data']['handlingMode']);
            self::assertIsArray($response['meta']);
            self::assertIsString($response['meta']['message']);
            self::assertStringContainsString('manuel', $response['meta']['message']);
        }

        $requests = $this->entityManager->getRepository(PrivacyRightsRequest::class)->findBy(['userId' => $user->getId()]);
        self::assertCount(5, $requests);
    }

    public function testInvalidRightTypeAndLongDetailsAreRejected(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/account/privacy-requests', [
            'rightType' => 'automatic_export',
            'details' => str_repeat('a', 1001),
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('rightType', $response['error']['details']);
        self::assertArrayHasKey('details', $response['error']['details']);
    }

    public function testCreatedRequestStoresUserTypeStatusAndTimestamp(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/account/privacy-requests', [
            'rightType' => PrivacyRightsRequest::RIGHT_PORTABILITY,
            'details' => 'Je souhaite connaître le processus de portabilité.',
        ]);

        self::assertResponseStatusCodeSame(201);
        $storedRequest = $this->entityManager->getRepository(PrivacyRightsRequest::class)->findOneBy(['userId' => $user->getId()]);
        self::assertInstanceOf(PrivacyRightsRequest::class, $storedRequest);
        self::assertSame(PrivacyRightsRequest::RIGHT_PORTABILITY, $storedRequest->getRightType());
        self::assertSame(PrivacyRightsRequest::STATUS_RECEIVED, $storedRequest->getStatus());
        self::assertSame(PrivacyRightsRequest::HANDLING_MANUAL_REVIEW, $storedRequest->getHandlingMode());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $storedRequest->getSubmittedAt());
        self::assertSame('Je souhaite connaître le processus de portabilité.', $storedRequest->getDetails());
    }

    public function testDetailsAtExactlyMaxLengthIsAccepted(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/account/privacy-requests', [
            'rightType' => PrivacyRightsRequest::RIGHT_ACCESS,
            'details' => str_repeat('a', 1000),
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testWhitespaceOnlyDetailsStoredAsNull(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/account/privacy-requests', [
            'rightType' => PrivacyRightsRequest::RIGHT_ACCESS,
            'details' => '   ',
        ]);

        self::assertResponseStatusCodeSame(201);
        $storedRequest = $this->entityManager->getRepository(PrivacyRightsRequest::class)->findOneBy(['userId' => $user->getId()]);
        self::assertInstanceOf(PrivacyRightsRequest::class, $storedRequest);
        self::assertNull($storedRequest->getDetails());
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles, ?string $displayName): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            $displayName,
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
