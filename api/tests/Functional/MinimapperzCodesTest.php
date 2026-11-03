<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Wallet\Domain\Entity\MinimapperzSetting;

/**
 * Story 49.2: the admin imports the codes Minimapperz gave; each registrant gets one, never the same as another.
 */
final class MinimapperzCodesTest extends FunctionalTestCase
{
    private User $admin;
    private User $alice;
    private User $bob;
    private User $carol;
    private User $dave;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $this->dave = $this->createUser('dave@example.org', ['ROLE_USER'], 'Dave');
        $this->event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'), published: true);
        $this->createRegistration($this->event->getId(), $this->alice->getId());
        $this->createRegistration($this->event->getId(), $this->bob->getId());
        $this->createRegistration($this->event->getId(), $this->carol->getId(), Registration::STATUS_CANCELLED);
        $this->entityManager->persist(new MinimapperzSetting($this->event->getId(), true, 'archilan-4', 'mmz_ev_key', 1, 2, 50));
        $this->entityManager->flush();
    }

    public function testTheAdminImportsTheListOnce(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->adminPath(), ['codes' => "MMZ-1\r\n  MMZ-2 \n\nMMZ-1\nbad code\nMMZ-3"]);

        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertSame(3, $data['added']);
        self::assertSame(0, $data['duplicates']);
        self::assertSame(['bad code'], $data['invalid']);

        $this->client->jsonRequest('POST', $this->adminPath(), ['codes' => "MMZ-3\nMMZ-4"]);
        $data = $this->data();
        self::assertSame(1, $data['added']);
        self::assertSame(1, $data['duplicates']);
        $overview = $data['overview'];
        self::assertIsArray($overview);
        self::assertSame(4, $overview['imported']);
        self::assertSame(4, $overview['free']);
        self::assertSame(2, $overview['registrantsWithoutCode']);
    }

    public function testNothingUsableIsRefused(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->adminPath(), ['codes' => "  \nwith space"]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testARegistrantGetsACodeAndKeepsIt(): void
    {
        $this->import("MMZ-1\nMMZ-2");

        $this->loginAs($this->alice);
        $first = $this->claim();
        $again = $this->claim();
        $this->loginAs($this->bob);
        $bobs = $this->claim();

        self::assertSame(['code' => 'MMZ-1', 'lanUrl' => 'https://app.minimapperz.com/lan/archilan-4/'], $first);
        self::assertSame($first, $again);
        self::assertSame('MMZ-2', $bobs['code'] ?? null);
    }

    public function testNoCodeForSomeoneNotRegisteredOrCancelled(): void
    {
        $this->import("MMZ-1\nMMZ-2");

        $this->loginAs($this->carol);
        self::assertNull($this->claim(), 'a cancelled registration');
        $this->loginAs($this->dave);
        self::assertNull($this->claim(), 'not registered');
        self::assertSame(2, $this->overview()['free']);
    }

    public function testNoCodeWhileTheLinkIsOffOrOnceTheEventIsOver(): void
    {
        $this->import('MMZ-1');
        $this->entityManager->getConnection()->executeStatement('UPDATE minimapperz_setting SET enabled = false');
        $this->loginAs($this->alice);
        self::assertNull($this->claim(), 'link off');

        $this->entityManager->getConnection()->executeStatement('UPDATE minimapperz_setting SET enabled = true');
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE event SET starts_at = '2020-01-01T10:00:00+00:00', ends_at = '2020-01-02T18:00:00+00:00' WHERE id = :id",
            ['id' => $this->event->getId()],
        );
        $this->entityManager->clear();
        self::assertNull($this->claim(), 'event over');
    }

    public function testNoCodeLeftGivesNothing(): void
    {
        $this->import('MMZ-1');
        $this->loginAs($this->alice);
        $this->claim();

        $this->loginAs($this->bob);
        self::assertNull($this->claim());
    }

    public function testTheAdminHandsCodesToEveryRegistrantAtOnce(): void
    {
        $this->import('MMZ-1');
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->adminPath().'/assign');

        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertSame(1, $data['assigned']);
        self::assertSame(1, $data['withoutCode'], 'one registrant left for want of a code');

        $this->import('MMZ-2');
        $this->client->jsonRequest('POST', $this->adminPath().'/assign');
        $data = $this->data();
        self::assertSame(1, $data['assigned']);
        self::assertSame(0, $data['withoutCode']);
        $overview = $data['overview'];
        self::assertIsArray($overview);
        self::assertSame(0, $overview['registrantsWithoutCode']);
        $codes = $overview['codes'];
        self::assertIsArray($codes);
        self::assertSame(['Alice', 'Bob'], array_map(static fn (mixed $row): mixed => is_array($row) ? $row['displayName'] : null, $codes));
    }

    public function testACancelledRegistrationKeepsItsCodeAndTheAdminCanTakeItBack(): void
    {
        $this->import('MMZ-1');
        $this->loginAs($this->alice);
        $this->claim();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE registration SET status = :cancelled WHERE user_id = :user',
            ['cancelled' => Registration::STATUS_CANCELLED, 'user' => $this->alice->getId()],
        );

        $this->loginAs($this->admin);
        $codes = $this->overview()['codes'];
        self::assertIsArray($codes);
        self::assertSame([['code' => 'MMZ-1', 'userId' => $this->alice->getId(), 'displayName' => 'Alice', 'registered' => false]], array_map(
            static fn (mixed $row): array => is_array($row) ? array_diff_key($row, ['assignedAt' => true]) : [],
            $codes,
        ));

        $this->client->jsonRequest('DELETE', $this->adminPath().'/MMZ-1/assignment');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->data()['free']);
        $this->client->jsonRequest('DELETE', $this->adminPath().'/MMZ-1/assignment');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAnAdminManagesTheCodes(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('GET', $this->adminPath());
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', $this->adminPath(), ['codes' => 'MMZ-1']);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', $this->adminPath().'/assign');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAVisitorAsksForNoCode(): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/minimapperz/code', $this->event->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    private function adminPath(): string
    {
        return sprintf('/api/v1/admin/events/%s/minimapperz/codes', $this->event->getId());
    }

    private function import(string $codes): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->adminPath(), ['codes' => $codes]);
        self::assertResponseStatusCodeSame(200);
    }

    /** @return array<array-key, mixed>|null */
    private function claim(): ?array
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/minimapperz/code', $this->event->getId()));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /** @return array<array-key, mixed> */
    private function overview(): array
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', $this->adminPath());
        self::assertResponseStatusCodeSame(200);

        return $this->data();
    }

    /** @return array<array-key, mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
