<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CurrentUserDisplayNameTest extends FunctionalTestCase
{
    public function testMeReturnsCommunityDisplayNameOverrideElseAccountName(): void
    {
        $user = $this->createUser('jean@example.org', slug: 'jean', displayName: 'Jean');
        $this->loginAs($user);

        // No override yet → the account display name.
        $this->client->jsonRequest('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame('Jean', $this->meDisplayName());

        // Set a community override → it becomes the pseudo returned by /auth/me.
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['displayName' => 'MasterKafey']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/auth/me');
        self::assertSame('MasterKafey', $this->meDisplayName());

        // Clearing it falls back to the account display name again.
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['displayName' => '']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/auth/me');
        self::assertSame('Jean', $this->meDisplayName());
    }

    private function meDisplayName(): mixed
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data['displayName'] ?? null;
    }
}
