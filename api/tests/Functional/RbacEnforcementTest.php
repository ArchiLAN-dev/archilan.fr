<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\AdminCreationAudit;
use App\Identity\Domain\DeletionAudit;
use App\Identity\Domain\RoleChangeAudit;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class RbacEnforcementTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(DeletionAudit::class),
            $this->entityManager->getClassMetadata(RoleChangeAudit::class),
            $this->entityManager->getClassMetadata(AdminCreationAudit::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousReceivesUnauthorizedOnProtectedEndpoints(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        foreach ($this->protectedRequests($target) as $request) {
            $this->request($request);
            self::assertResponseStatusCodeSame(401, $request['method'].' '.$request['path']);
            $this->assertErrorShape('unauthenticated');
        }
    }

    public function testStandardCanAccessOwnAccountButNotAdminEndpoints(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/account/profile');
        self::assertResponseIsSuccessful();

        foreach ($this->adminRequests($target) as $request) {
            $this->request($request);
            self::assertResponseStatusCodeSame(403, $request['method'].' '.$request['path']);
            $this->assertErrorShape('forbidden');
        }
    }

    public function testMemberCanAccessOwnAccountButNotAdminEndpoints(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Member');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($member);

        $this->client->jsonRequest('GET', '/api/v1/account/profile');
        self::assertResponseIsSuccessful();

        foreach ($this->adminRequests($target) as $request) {
            $this->request($request);
            self::assertResponseStatusCodeSame(403, $request['method'].' '.$request['path']);
            $this->assertErrorShape('forbidden');
        }
    }

    public function testAdminCanReachAdminEndpointsServerSide(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/users');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/users/%s/role', $target->getId()), [
            'role' => 'member',
            'confirmed' => true,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/v1/admin/users/admins', [
            'email' => 'new-admin@example.org',
            'password' => 'correct horse battery staple',
            'displayName' => 'New Admin',
            'roles' => ['ROLE_USER'],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return list<array{method: string, path: string, body?: array<string, mixed>}>
     */
    private function protectedRequests(User $target): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/v1/account/profile'],
            ['method' => 'POST', 'path' => '/api/v1/account/privacy-requests', 'body' => ['rightType' => 'access']],
            ['method' => 'DELETE', 'path' => '/api/v1/account'],
            ['method' => 'GET', 'path' => '/api/v1/events/nonexistent/registration-eligibility'],
            ['method' => 'POST', 'path' => '/api/v1/events/nonexistent/verify-private-access', 'body' => ['password' => 'x']],
            ['method' => 'POST', 'path' => '/api/v1/events/nonexistent/registrations'],
            ['method' => 'GET', 'path' => '/api/v1/registrations/nonexistent/game-selection'],
            ['method' => 'PUT', 'path' => '/api/v1/registrations/nonexistent/game-selection', 'body' => ['gameIds' => []]],
            ['method' => 'PUT', 'path' => '/api/v1/registrations/nonexistent/slots/nonexistent/yaml', 'body' => ['playerYaml' => 'name: Test']],
            ['method' => 'DELETE', 'path' => '/api/v1/registrations/nonexistent'],
            ['method' => 'POST', 'path' => '/api/v1/registrations/nonexistent/submit'],
            ...$this->adminRequests($target),
        ];
    }

    /**
     * @return list<array{method: string, path: string, body?: array<string, mixed>}>
     */
    private function adminRequests(User $target): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/v1/admin/users'],
            ['method' => 'GET', 'path' => '/api/v1/admin/dashboard-stats'],
            ['method' => 'GET', 'path' => '/api/v1/admin/events'],
            [
                'method' => 'POST',
                'path' => '/api/v1/admin/events',
                'body' => [
                    'title' => 'Draft',
                    'description' => 'Draft event',

                    'startsAt' => '2027-05-31T10:00:00+00:00',
                    'endsAt' => '2027-05-31T22:00:00+00:00',
                    'venue' => 'Clermont-Ferrand',
                    'capacity' => 24,
                    'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
                    'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
                    'isPublic' => true,
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/v1/admin/events/nonexistent',
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/events/nonexistent',
                'body' => [
                    'title' => 'Draft',
                    'description' => 'Draft event',

                    'startsAt' => '2027-05-31T10:00:00+00:00',
                    'endsAt' => '2027-05-31T22:00:00+00:00',
                    'venue' => 'Clermont-Ferrand',
                    'capacity' => 24,
                    'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
                    'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
                    'isPublic' => true,
                ],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/events/nonexistent/status',
                'body' => ['status' => Event::STATUS_PUBLISHED],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/events/nonexistent/private-access',
                'body' => ['password' => 'private-access-passphrase'],
            ],
            [
                'method' => 'GET',
                'path' => '/api/v1/admin/events/nonexistent/game-selection',
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/events/nonexistent/game-selection',
                'body' => ['gameSelectionEnabled' => false, 'games' => []],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/events/nonexistent/recap',
                'body' => ['vodUrl' => null, 'recapPostSlug' => null],
            ],
            ['method' => 'GET', 'path' => '/api/v1/admin/events/nonexistent/registrations'],
            ['method' => 'GET', 'path' => '/api/v1/admin/events/nonexistent/registrations/export'],
            ['method' => 'GET', 'path' => '/api/v1/admin/events/nonexistent/registrations/nonexistent'],
            ['method' => 'DELETE', 'path' => '/api/v1/admin/events/nonexistent/registrations/nonexistent'],
            [
                'method' => 'POST',
                'path' => '/api/v1/admin/events/nonexistent/registrations/nonexistent/messages',
                'body' => ['subject' => 'Test', 'body' => 'Body'],
            ],
            ['method' => 'GET', 'path' => '/api/v1/admin/games'],
            ['method' => 'GET', 'path' => '/api/v1/admin/games/nonexistent'],
            [
                'method' => 'POST',
                'path' => '/api/v1/admin/games',
                'body' => [
                    'name' => 'Game',
                    'slug' => 'game',
                    'description' => 'Game description',
                    'coverImageAlt' => 'Game cover',
                    'coverImageCredit' => 'Publisher',
                    'availability' => Game::AVAILABILITY_AVAILABLE,
                ],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/games/nonexistent',
                'body' => [
                    'name' => 'Game',
                    'slug' => 'game',
                    'description' => 'Game description',
                    'coverImageAlt' => 'Game cover',
                    'coverImageCredit' => 'Publisher',
                    'availability' => Game::AVAILABILITY_AVAILABLE,
                ],
            ],
            ['method' => 'PATCH', 'path' => '/api/v1/admin/games/nonexistent/apworld'],
            ['method' => 'DELETE', 'path' => '/api/v1/admin/games/nonexistent'],
            [
                'method' => 'POST',
                'path' => '/api/v1/admin/events/nonexistent/sessions',
                'body' => ['slots' => []],
            ],
            ['method' => 'GET', 'path' => '/api/v1/admin/sessions/nonexistent'],
            [
                'method' => 'PATCH',
                'path' => '/api/v1/admin/sessions/nonexistent/status',
                'body' => ['status' => 'validating'],
            ],
            ['method' => 'DELETE', 'path' => '/api/v1/admin/sessions/nonexistent'],
            ['method' => 'GET', 'path' => '/api/v1/admin/events/nonexistent/sessions'],
            ['method' => 'GET', 'path' => '/api/v1/admin/events/nonexistent/sessions/builder'],
            ['method' => 'POST', 'path' => '/api/v1/admin/events/nonexistent/sessions/preflight', 'body' => ['slots' => []]],
            ['method' => 'POST', 'path' => '/api/v1/admin/sessions/nonexistent/generate'],
            ['method' => 'POST', 'path' => '/api/v1/admin/sessions/nonexistent/launch'],
            ['method' => 'POST', 'path' => '/api/v1/admin/sessions/nonexistent/stop'],
            ['method' => 'POST', 'path' => '/api/v1/admin/sessions/nonexistent/restart'],
            ['method' => 'GET', 'path' => '/api/v1/admin/sessions/nonexistent/yamls.zip'],
            [
                'method' => 'PATCH',
                'path' => sprintf('/api/v1/admin/users/%s/role', $target->getId()),
                'body' => ['role' => 'member', 'confirmed' => true],
            ],
            [
                'method' => 'POST',
                'path' => '/api/v1/admin/users/admins',
                'body' => [
                    'email' => 'created-admin@example.org',
                    'password' => 'correct horse battery staple',
                    'displayName' => 'Created Admin',
                ],
            ],
        ];
    }

    /**
     * @param array{method: string, path: string, body?: array<string, mixed>} $request
     */
    private function request(array $request): void
    {
        $this->client->jsonRequest($request['method'], $request['path'], $request['body'] ?? []);
    }

    private function assertErrorShape(string $expectedCode): void
    {
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame($expectedCode, $response['error']['code']);
        self::assertIsString($response['error']['message']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayNotHasKey('data', $response);
    }
}
