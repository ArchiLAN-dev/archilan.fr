<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fichiers de génération d'une partie d'event (story 17.32) : lus dans l'archive durable de la
 * session, comme en partie privée, et filtrés sur les slots du joueur.
 */
final class RegistrationPatchTest extends FunctionalTestCase
{
    private const string OWN_PATCH = 'AP_123_P1_alice_LM.aplm';
    private const string PREFIXED_NEIGHBOUR = 'AP_123_P2_alice_LM_two.aplm';

    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testTheRegistrantListsOnlyTheirOwnPatchesWithASignedLink(): void
    {
        [$alice, $registration] = $this->generatedEventSession(Session::STATUS_GENERATED);
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/patches', $registration->getId()));

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $files = $data['files'] ?? null;
        self::assertIsArray($files);
        self::assertCount(1, $files, 'ni le voisin au nom préfixé, ni la multidata, ni le spoiler');
        $file = $files[0];
        self::assertIsArray($file);
        self::assertSame(self::OWN_PATCH, $file['name'] ?? null);
        self::assertIsString($file['url'] ?? null);
        self::assertStringContainsString('archive=', $file['url']);
    }

    public function testTheRegistrantDownloadsTheirPatchFromTheArchive(): void
    {
        [$alice, $registration] = $this->generatedEventSession(Session::STATUS_FINISHED);
        $this->loginAs($alice);

        $this->client->request('GET', sprintf('/api/v1/registrations/%s/patches/%s', $registration->getId(), self::OWN_PATCH));

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertStringContainsString(self::OWN_PATCH, (string) $response->headers->get('Content-Disposition'));
    }

    public function testAnotherSlotsPatchIsForbidden(): void
    {
        [$alice, $registration] = $this->generatedEventSession(Session::STATUS_RUNNING);
        $this->loginAs($alice);

        $this->client->request('GET', sprintf('/api/v1/registrations/%s/patches/%s', $registration->getId(), self::PREFIXED_NEIGHBOUR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testNothingIsListedBeforeTheGeneration(): void
    {
        [$alice, $registration] = $this->generatedEventSession(Session::STATUS_READY);
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/patches', $registration->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['files' => []]], $this->decodedJsonResponse());
    }

    public function testSomeoneElseCannotDownloadThroughTheRegistration(): void
    {
        [, $registration] = $this->generatedEventSession(Session::STATUS_RUNNING);
        $this->loginAs($this->createUser('mallory@example.org', ['ROLE_USER'], 'Mallory'));

        $this->client->request('GET', sprintf('/api/v1/registrations/%s/patches/%s', $registration->getId(), self::OWN_PATCH));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Alice joue « alice_LM » ; un autre inscrit joue « alice_LM_two », dont le nom commence par le sien.
     * Seule une session générée a une archive ; avant, la clé n'est pas enregistrée.
     *
     * @return array{User, Registration}
     */
    private function generatedEventSession(string $status): array
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $registration = $this->confirmedRegistration($alice);
        $other = $this->confirmedRegistration($bob);

        $now = new \DateTimeImmutable('2026-10-24T10:00:00+00:00');
        $session = Session::create('sess-event-patch', 'evt-patch', $now);
        $path = [Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED];
        $generated = Session::STATUS_READY !== $status;
        foreach ($generated ? $path : [Session::STATUS_VALIDATING, Session::STATUS_READY] as $step) {
            $session->transition($step, $now);
        }
        if ($generated) {
            $session->markGenerated('sess-event-patch/output/archive.zip');
            $this->uploadArchive('sess-event-patch/output/archive.zip');
        }
        if (Session::STATUS_RUNNING === $status || Session::STATUS_FINISHED === $status) {
            $session->transition(Session::STATUS_LAUNCHING, $now);
            $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        }
        if (Session::STATUS_FINISHED === $status) {
            $session->transition(Session::STATUS_FINISHED, $now);
        }
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), 'game-lm', 'alice_LM', 1));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $other->getId(), 'game-lm', 'alice_LM_two', 2));
        $this->entityManager->flush();

        return [$alice, $registration];
    }

    private function confirmedRegistration(User $user): Registration
    {
        $registration = $this->createRegistration('evt-patch', $user->getId());
        $registration->confirm(new \DateTimeImmutable('2026-10-20T10:00:00+00:00'));
        $this->entityManager->flush();

        return $registration;
    }

    private function uploadArchive(string $key): void
    {
        $path = tempnam(sys_get_temp_dir(), 'patch');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString(self::OWN_PATCH, 'patch-alice');
        $zip->addFromString(self::PREFIXED_NEIGHBOUR, 'patch-voisin');
        $zip->addFromString('AP_123.archipelago', 'multidata');
        $zip->addFromString('AP_123_Spoiler.txt', 'spoiler');
        $zip->close();

        $contents = file_get_contents($path);
        unlink($path);
        self::assertIsString($contents);
        new NullMinioStorage()->upload('sessions', $key, $contents);
    }
}
