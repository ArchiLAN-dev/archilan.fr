<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Wallet\Domain\Entity\BlindTestSetting;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.40: the blind test app credits event pelles for the sources found, under the site's rules.
 */
final class BlindTestTest extends FunctionalTestCase
{
    private const string SECRET = 'test-blind-test-secret';

    private User $admin;
    private Event $event;
    private User $alice;
    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'), published: true);
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $this->createRegistration($this->event->getId(), $this->alice->getId());
        $this->createRegistration($this->event->getId(), $this->carol->getId(), Registration::STATUS_CANCELLED);
        $this->entityManager->persist(new BlindTestSetting($this->event->getId(), true, 2, 5));
        $this->entityManager->flush();
    }

    public function testAFoundSourceIsCreditedAtTheEventsRate(): void
    {
        $this->sendCredit($this->body('blindtest:e:0412:ff7:alice', 'Final Fantasy VII'));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['data' => ['credited' => 2, 'balance' => 2]], $this->decodedJsonResponse());
        $movements = $this->movements();
        self::assertCount(1, $movements);
        self::assertSame(PelleKind::Event, $movements[0]->getKind());
        self::assertSame('Blind test : Final Fantasy VII', $movements[0]->getLabel());
    }

    public function testTheSameFindSentTwiceCreditsOnce(): void
    {
        $body = $this->body('blindtest:e:0412:ff7:alice', 'Final Fantasy VII');
        $this->sendCredit($body);
        $this->sendCredit($body);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['credited' => 0, 'balance' => 2]], $this->decodedJsonResponse());
        self::assertCount(1, $this->movements());
    }

    public function testTheCapStopsTheCredits(): void
    {
        $this->sendCredit($this->body('blindtest:1', 'Celeste'));
        $this->sendCredit($this->body('blindtest:2', 'Tunic'));
        $this->sendCredit($this->body('blindtest:3', 'Hades'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('cap_reached', $this->errorCode());
        self::assertCount(2, $this->movements());
    }

    public function testAWrongOrStaleSignatureIsRefused(): void
    {
        $body = $this->body('blindtest:1', 'Celeste');
        $this->sendCredit($body, signature: str_repeat('0', 64));
        self::assertResponseStatusCodeSame(401);

        $stale = (string) (time() - 600);
        $this->sendCredit($body, timestamp: $stale, signature: hash_hmac('sha256', $stale.'.'.$body, self::SECRET));
        self::assertResponseStatusCodeSame(401);

        self::assertSame([], $this->movements());
    }

    public function testOnlyActiveRegistrantsEarn(): void
    {
        $this->sendCredit($this->body('blindtest:1', 'Celeste', $this->carol));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('not_registered', $this->errorCode());
    }

    public function testASwitchedOffBlindTestCreditsNothing(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/events/%s/blind-test', $this->event->getId()), ['enabled' => false, 'pellesPerSource' => 2, 'capPerPlayer' => 5]);
        self::assertResponseStatusCodeSame(200);

        $this->sendCredit($this->body('blindtest:1', 'Celeste'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('blind_test_disabled', $this->errorCode());
    }

    public function testAnEndedEventCreditsNothing(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE event SET starts_at = '2020-01-01T10:00:00+00:00', ends_at = '2020-01-02T18:00:00+00:00' WHERE id = :id",
            ['id' => $this->event->getId()],
        );
        $this->entityManager->clear();

        $this->sendCredit($this->body('blindtest:1', 'Celeste'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('event_not_running', $this->errorCode());
    }

    public function testAKeyWithoutThePrefixIsRefused(): void
    {
        $this->sendCredit($this->body('quest:1', 'Celeste'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_blind_test_credit', $this->errorCode());
    }

    public function testARegistrantIsShownTheWayIntoTheApp(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/blind-test/access', $this->event->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['url' => 'https://blindtest.example.test/connexion']], $this->decodedJsonResponse());
    }

    public function testSomeoneNotRegisteredIsNotShownTheWayIn(): void
    {
        $this->loginAs($this->carol);
        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/blind-test/access', $this->event->getId()));

        self::assertResponseStatusCodeSame(200, 'every event page asks: « not now » is an answer, not an error');
        self::assertSame(['data' => ['url' => null]], $this->decodedJsonResponse());
    }

    public function testAOneTimeCodeTellsTheAppWhoPlaysOnce(): void
    {
        $code = $this->loginCode($this->alice, 'state-of-the-app-1234');

        $this->sendSigned('/api/v1/integrations/blind-test/codes/echange', json_encode(['code' => $code], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame($this->alice->getId(), $data['userId'] ?? null);
        self::assertSame('Alice', $data['displayName'] ?? null);
        self::assertSame('state-of-the-app-1234', $data['state'] ?? null);
        self::assertSame($this->event->getEndsAt()->getTimestamp(), $data['exp'] ?? null);

        $this->sendSigned('/api/v1/integrations/blind-test/codes/echange', json_encode(['code' => $code], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404, 'a code is served once');
    }

    public function testTheReturnAddressComesFromTheConfigurationOnly(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/blind-test/codes', $this->event->getId()), ['state' => 'state-of-the-app-1234', 'redirect' => 'https://evil.example']);

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $redirect = $data['redirect'] ?? null;
        self::assertIsString($redirect);
        self::assertStringStartsWith('https://blindtest.example.test/connexion/retour?code=', $redirect);
        self::assertStringEndsWith('&state=state-of-the-app-1234', $redirect);
    }

    public function testAnExpiredCodeIsWorthNothing(): void
    {
        $code = $this->loginCode($this->alice, 'state-of-the-app-1234');
        $this->entityManager->getConnection()->executeStatement("UPDATE blind_test_login_code SET expires_at = '2020-01-01T00:00:00+00:00'");

        $this->sendSigned('/api/v1/integrations/blind-test/codes/echange', json_encode(['code' => $code], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
    }

    public function testACodeIsOnlyIssuedForTheAppsOwnState(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/blind-test/codes', $this->event->getId()), ['state' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_state', $this->errorCode());
    }

    public function testSomeoneNotRegisteredGetsNoCode(): void
    {
        $this->loginAs($this->carol);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/blind-test/codes', $this->event->getId()), ['state' => 'state-of-the-app-1234']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnsignedExchangeIsRefused(): void
    {
        $code = $this->loginCode($this->alice, 'state-of-the-app-1234');

        $this->sendSigned('/api/v1/integrations/blind-test/codes/echange', json_encode(['code' => $code], \JSON_THROW_ON_ERROR), signature: str_repeat('0', 64));

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheAppListsTheEventsItMayPrepare(): void
    {
        $draft = $this->createEvent('Brouillon', new \DateTimeImmutable('+10 days'), new \DateTimeImmutable('+11 days'));
        $later = $this->createEvent('ArchiLAN #5', new \DateTimeImmutable('+30 days'), new \DateTimeImmutable('+31 days'), published: true);

        $timestamp = (string) time();
        $this->client->request('GET', '/api/v1/integrations/blind-test/evenements', server: [
            'HTTP_X_ARCHILAN_TIMESTAMP' => $timestamp,
            'HTTP_X_ARCHILAN_SIGNATURE' => hash_hmac('sha256', $timestamp.'.', self::SECRET),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $ids = array_map(static fn (mixed $e): mixed => is_array($e) ? ($e['id'] ?? null) : null, $data);
        self::assertSame([$this->event->getId(), $later->getId()], $ids, 'published or under way, soonest first');
        self::assertNotContains($draft->getId(), $ids);
        self::assertIsArray($data[0]);
        self::assertSame('ArchiLAN #4', $data[0]['title'] ?? null);
        self::assertTrue($data[0]['blindTestEnabled'] ?? null);
        self::assertIsArray($data[1]);
        self::assertFalse($data[1]['blindTestEnabled'] ?? null);
    }

    public function testTheEventListNeedsTheSignature(): void
    {
        $this->client->request('GET', '/api/v1/integrations/blind-test/evenements', server: [
            'HTTP_X_ARCHILAN_TIMESTAMP' => (string) time(),
            'HTTP_X_ARCHILAN_SIGNATURE' => str_repeat('0', 64),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnAdminTunesTheBlindTest(): void
    {
        $this->loginAs($this->admin);
        $path = sprintf('/api/v1/admin/events/%s/blind-test', $this->event->getId());

        $this->client->jsonRequest('PUT', $path, ['enabled' => true, 'pellesPerSource' => 3, 'capPerPlayer' => 90]);
        self::assertResponseStatusCodeSame(200);
        $this->client->jsonRequest('GET', $path);
        self::assertSame(['data' => [
            'enabled' => true,
            'pellesPerSource' => 3,
            'capPerPlayer' => 90,
            'consoleUrl' => 'https://blindtest.example.test/admin?event='.$this->event->getId(),
        ]], $this->decodedJsonResponse());

        $this->client->jsonRequest('PUT', $path, ['enabled' => true, 'pellesPerSource' => 0, 'capPerPlayer' => 90]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyAnAdminTunesTheBlindTest(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/blind-test', $this->event->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    private function body(string $key, string $source, ?User $member = null): string
    {
        return json_encode([
            'eventId' => $this->event->getId(),
            'userId' => ($member ?? $this->alice)->getId(),
            'uniqueKey' => $key,
            'track' => '0412',
            'source' => $source,
        ], \JSON_THROW_ON_ERROR);
    }

    private function sendCredit(string $body, ?string $timestamp = null, ?string $signature = null): void
    {
        $this->sendSigned('/api/v1/integrations/blind-test/credits', $body, $timestamp, $signature);
    }

    private function loginCode(User $member, string $state): string
    {
        $this->loginAs($member);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/blind-test/codes', $this->event->getId()), ['state' => $state]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $redirect = $data['redirect'] ?? null;
        self::assertIsString($redirect);
        parse_str((string) parse_url($redirect, \PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    private function sendSigned(string $path, string $body, ?string $timestamp = null, ?string $signature = null): void
    {
        $timestamp ??= (string) time();
        $this->client->request(
            'POST',
            $path,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ARCHILAN_TIMESTAMP' => $timestamp,
                'HTTP_X_ARCHILAN_SIGNATURE' => $signature ?? hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
            ],
            $body,
        );
    }

    private function errorCode(): mixed
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;

        return is_array($error) ? ($error['code'] ?? null) : null;
    }

    /** @return list<PelleMovement> */
    private function movements(): array
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $this->alice->getId(), 'reason' => PelleReason::BlindTest]);
    }
}
