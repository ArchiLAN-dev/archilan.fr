<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 47.4 (spike): the LAN MediaMTX server asks the site before letting a stream in (key from .env.test). */
final class MediaAuthSpikeTest extends FunctionalTestCase
{
    private const string KEY = 'test-media-spike-key';

    public function testThePublicationWithTheKeyGoesThroughByWhipOrSrt(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/media-auth', $this->payload(['token' => self::KEY]));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', '/api/v1/media-auth', $this->payload(['user' => 'joueur', 'password' => self::KEY, 'protocol' => 'srt']));
        self::assertResponseStatusCodeSame(200);
    }

    public function testAWrongKeyAReadOrAnotherPathIsRefused(): void
    {
        foreach ([
            $this->payload(['token' => 'wrong']),
            $this->payload([]),
            $this->payload(['token' => self::KEY, 'action' => 'read']),
            $this->payload(['token' => self::KEY, 'path' => 'regie']),
        ] as $payload) {
            $this->client->jsonRequest('POST', '/api/v1/media-auth', $payload);
            self::assertResponseStatusCodeSame(401);
        }

        $this->client->request('POST', '/api/v1/media-auth', server: ['CONTENT_TYPE' => 'application/json'], content: 'not json');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return [
            'ip' => '192.168.1.20',
            'user' => '',
            'password' => '',
            'token' => '',
            'action' => 'publish',
            'path' => 'p-7f3k2a',
            'protocol' => 'webrtc',
            'id' => null,
            'query' => '',
            'userAgent' => 'OBS/31.1.2',
            ...$overrides,
        ];
    }
}
