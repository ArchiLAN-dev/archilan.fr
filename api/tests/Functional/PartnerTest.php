<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Story 45.1: the partners of the association, and their admin. */
final class PartnerTest extends FunctionalTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

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

    public function testVisitorsSeeOnlyShownPartnersWithALogoInOrder(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $first = $this->createPartner('Ludothèque');
        $hidden = $this->createPartner('Masqué', false);
        $noLogo = $this->createPartner('Sans logo');
        $second = $this->createPartner('Boutique');
        foreach ([$first, $hidden, $second] as $id) {
            $this->uploadLogo($id, self::PNG, 'logo.png');
            self::assertResponseStatusCodeSame(204);
        }

        $this->client->request('GET', '/api/v1/partners');
        self::assertResponseIsSuccessful();
        $partners = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($partners);
        self::assertSame([$first, $second], array_column($partners, 'id'));
        self::assertIsArray($partners[0]);
        self::assertSame('Ludothèque', $partners[0]['name']);
        self::assertSame('https://ludo.example', $partners[0]['url']);
        self::assertIsString($partners[0]['logoUrl']);
        self::assertStringContainsString(sprintf('content-logos/%s/logo-', $first), $partners[0]['logoUrl']);
        self::assertArrayNotHasKey('active', $partners[0]);

        $this->client->request('GET', '/api/v1/admin/partners');
        self::assertResponseIsSuccessful();
        $all = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($all);
        self::assertSame([$first, $hidden, $noLogo, $second], array_column($all, 'id'));
        self::assertSame([true, false, false, true], array_column($all, 'visible'));
        self::assertSame([true, false, true, true], array_column($all, 'active'));
    }

    public function testTheAdminEditsAndDeletesAPartner(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createPartner('Ludo');

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/partners/%s', $id), ['name' => 'Ludothèque', 'description' => 'Des jeux à prêter.', 'url' => 'https://ludo.example/fr', 'active' => false, 'logoOnDark' => true]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/admin/partners');
        $all = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($all);
        self::assertIsArray($all[0]);
        self::assertSame('Ludothèque', $all[0]['name']);
        self::assertSame('Des jeux à prêter.', $all[0]['description']);
        self::assertSame('https://ludo.example/fr', $all[0]['url']);
        self::assertFalse($all[0]['active']);
        // Story 45.5: a light logo asks for a dark chip; a partner starts on the white one.
        self::assertTrue($all[0]['logoOnDark']);

        $this->client->request('DELETE', sprintf('/api/v1/admin/partners/%s', $id));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', sprintf('/api/v1/admin/partners/%s', $id));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('partner_not_found', $this->errorCode($this->decodedJsonResponse()));
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/partners/%s', $id), $this->payload('Ludo'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidFieldsAreRefused(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', '/api/v1/admin/partners', [...$this->payload('Pirate'), 'url' => 'javascript:alert(1)']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('partner_url_invalid', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/partners', $this->payload(''));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('partner_name_invalid', $this->errorCode($this->decodedJsonResponse()));

        $this->client->request('POST', '/api/v1/admin/partners', [], [], ['CONTENT_TYPE' => 'application/json'], 'nope');
        self::assertResponseStatusCodeSame(400);
    }

    public function testReorderSetsEachListedPartnerToItsRank(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $a = $this->createPartner('A');
        $b = $this->createPartner('B');
        $c = $this->createPartner('C');

        $this->client->jsonRequest('POST', '/api/v1/admin/partners/reorder', ['ids' => [$c, 'unknown', $a, $b]]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$c, $a, $b], $this->adminOrder());

        $this->client->jsonRequest('POST', '/api/v1/admin/partners/reorder', ['ids' => 'nope']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testALogoIsUploadedThenRemoved(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createPartner('Ludo');

        $this->uploadLogo($id, self::PNG, 'logo.png');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/partners');
        self::assertCount(1, $this->listData());

        $this->client->request('DELETE', sprintf('/api/v1/admin/partners/%s/logo', $id));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/partners');
        self::assertSame([], $this->listData());
    }

    public function testOnlyRasterLogosUnderTwoMegabytesAreAccepted(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createPartner('Ludo');

        // An SVG named .png is still an SVG: the type is read from the content.
        $this->uploadLogo($id, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_invalid_type', $this->errorCode($this->decodedJsonResponse()));

        $this->uploadLogo($id, 'du texte', 'logo.txt');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_invalid_type', $this->errorCode($this->decodedJsonResponse()));

        $this->uploadLogo($id, self::PNG.str_repeat("\x00", 2 * 1024 * 1024), 'logo.png');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_too_large', $this->errorCode($this->decodedJsonResponse()));

        $this->client->request('POST', sprintf('/api/v1/admin/partners/%s/logo', $id));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('missing_file', $this->errorCode($this->decodedJsonResponse()));

        $this->uploadLogo('unknown', self::PNG, 'logo.png');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAdminsManagePartners(): void
    {
        $this->client->request('GET', '/api/v1/admin/partners');
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER']));
        $this->client->request('GET', '/api/v1/admin/partners');
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/admin/partners', $this->payload('Pirate'));
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/admin/partners/reorder', ['ids' => []]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/v1/partners');
        self::assertResponseIsSuccessful();
    }

    private function createPartner(string $name, bool $active = true): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/partners', [...$this->payload($name), 'active' => $active]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /** @return array<string, string|bool> */
    private function payload(string $name): array
    {
        return ['name' => $name, 'description' => '', 'url' => 'https://ludo.example', 'active' => true];
    }

    private function uploadLogo(string $id, string $contents, string $filename): void
    {
        $tmpFile = (string) tempnam(sys_get_temp_dir(), 'test_partner_');
        file_put_contents($tmpFile, $contents);
        $this->client->request('POST', sprintf('/api/v1/admin/partners/%s/logo', $id), [], ['file' => new UploadedFile($tmpFile, $filename, null, null, true)]);
        unlink($tmpFile);
    }

    /** @return list<mixed> */
    private function adminOrder(): array
    {
        $this->client->request('GET', '/api/v1/admin/partners');
        self::assertResponseIsSuccessful();

        return array_column($this->listData(), 'id');
    }

    /** @return array<mixed> */
    private function listData(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /** @param array<mixed> $body */
    private function errorCode(array $body): string
    {
        self::assertIsArray($body['error'] ?? null);
        self::assertIsString($body['error']['code'] ?? null);

        return $body['error']['code'];
    }
}
