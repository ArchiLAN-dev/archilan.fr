<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;

/**
 * Story 30.44, through the API: the name style (legendary admin, epic member) on the profile and on the cards, read
 * from the current status, and the owner's switch to turn it off.
 */
final class CommunityTitledNameTest extends FunctionalTestCase
{
    public function testAMemberNameIsEpicOnTheProfileAndOnTheCards(): void
    {
        $this->loginAs($this->withMembership($this->createUser('sil@example.org', slug: 'sil'), '-1 month', '+11 months'));
        $this->commentOnOwnProfile('sil');

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertTrue($this->data()['titledName']);
        self::assertSame('epic', $this->data()['titledNameStyle']);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/sil');
        self::assertSame('epic', $this->data()['nameStyle']);
        self::assertSame('epic', $this->commentAuthor('sil')['nameStyle']);
    }

    public function testAnAdminNameIsLegendary(): void
    {
        $this->loginAs($this->createUser('gol@example.org', ['ROLE_USER', 'ROLE_ADMIN'], slug: 'gol'));
        $this->commentOnOwnProfile('gol');

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/gol');
        self::assertSame('legendary', $this->data()['nameStyle']);
        self::assertSame('legendary', $this->commentAuthor('gol')['nameStyle']);
    }

    public function testNeitherMemberNorAdminHasAPlainName(): void
    {
        $this->loginAs($this->createUser('pla@example.org', slug: 'pla'));
        $this->commentOnOwnProfile('pla');

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertNull($this->data()['titledNameStyle'], 'nothing to offer in the editor');

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/pla');
        self::assertNull($this->data()['nameStyle']);
        self::assertNull($this->commentAuthor('pla')['nameStyle']);
    }

    public function testAnExpiredMembershipLosesTheEffect(): void
    {
        $this->loginAs($this->withMembership($this->createUser('exp@example.org', slug: 'exp'), '-2 years', '-1 year'));
        $this->commentOnOwnProfile('exp');

        self::assertNull($this->commentAuthor('exp')['nameStyle']);
    }

    public function testTheOwnerCanTurnItOffAndAnOmittedValueIsKept(): void
    {
        $this->loginAs($this->withMembership($this->createUser('off@example.org', slug: 'off'), '-1 month', '+11 months'));
        $this->commentOnOwnProfile('off');

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['titledName' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->data()['titledName']);
        self::assertSame('epic', $this->data()['titledNameStyle'], 'still offered, for the preview');

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerPreset' => 'neon']);
        self::assertFalse($this->data()['titledName']);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/off');
        self::assertNull($this->data()['nameStyle']);
        self::assertNull($this->commentAuthor('off')['nameStyle']);
    }

    public function testANonBooleanIsRefused(): void
    {
        $this->loginAs($this->createUser('bad@example.org', slug: 'bad'));

        foreach (['yes', 1, 0] as $invalid) {
            $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['titledName' => $invalid]);
            self::assertResponseStatusCodeSame(422);
        }
    }

    private function withMembership(User $user, string $start, string $end): User
    {
        $from = new \DateTimeImmutable($start);
        $this->entityManager->persist(Membership::create($user->getId(), $from, new \DateTimeImmutable($end), 'admin', null, null, $from));
        $this->entityManager->flush();

        return $user;
    }

    private function commentOnOwnProfile(string $slug): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'public']);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/'.$slug.'/comments', ['body' => 'Salut']);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<mixed>
     */
    private function commentAuthor(string $slug): array
    {
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/'.$slug.'/comments');
        $comments = $this->data();
        self::assertIsArray($comments[0]);
        $author = $comments[0]['author'];
        self::assertIsArray($author);

        return $author;
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
