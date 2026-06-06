<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\PrivacyRightsRequest;

final class PrivacyRightsRequestTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
}
