<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class SteamAccountEndpointTest extends FunctionalTestCase
{
    public function testSaveThenProfileExposesSteamProfile(): void
    {
        $user = $this->createUser('player@example.com');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/account/steam', ['steamProfile' => '76561197960287930']);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/account/profile');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('76561197960287930', $data['steamProfile']);
    }

    public function testSaveRejectsUnparseableProfile(): void
    {
        $this->loginAs($this->createUser('player@example.com'));

        $this->client->jsonRequest('PUT', '/api/v1/account/steam', ['steamProfile' => 'bad profile !!']);

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('steam_invalid_input', $error['code']);
    }

    public function testDeleteClearsSteamProfile(): void
    {
        $user = $this->createUser('player@example.com');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/account/steam', ['steamProfile' => 'gaben']);
        self::assertResponseIsSuccessful();

        $this->client->request('DELETE', '/api/v1/account/steam');
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/account/profile');
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertNull($data['steamProfile']);
    }

    public function testSaveRequiresAuthentication(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/account/steam', ['steamProfile' => '76561197960287930']);

        self::assertResponseStatusCodeSame(401);
    }
}
