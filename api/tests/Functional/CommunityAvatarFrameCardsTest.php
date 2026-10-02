<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\CommunityProfile;

/**
 * Story 30.47: a member's avatar frame follows their photo on every card surface, with the story 30.46 rule (a
 * legendary frame only while its owner is admin).
 */
final class CommunityAvatarFrameCardsTest extends FunctionalTestCase
{
    public function testDirectoryCardsCarryTheDisplayedFrame(): void
    {
        $now = new \DateTimeImmutable();
        $admin = $this->createUser('ada@example.org', ['ROLE_USER', 'ROLE_ADMIN'], slug: 'ada');
        $member = $this->createUser('max@example.org', slug: 'max');
        $demoted = $this->createUser('dee@example.org', slug: 'dee');
        $plain = $this->createUser('pia@example.org', slug: 'pia');

        foreach ([[$admin, 'fire'], [$member, 'gold'], [$demoted, 'cosmic'], [$plain, null]] as [$user, $frame]) {
            $this->entityManager->persist(new CommunityProfile(bin2hex(random_bytes(16)), $user->getId(), $now, $now, avatarFrame: $frame));
        }
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/directory');
        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $frames = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('avatarFrame', $row);
            self::assertIsString($row['slug']);
            $frames[$row['slug']] = $row['avatarFrame'];
        }

        self::assertSame('fire', $frames['ada']);
        self::assertSame('gold', $frames['max']);
        // A legendary frame on a non-admin account is not shown (kept stored for a re-promotion).
        self::assertNull($frames['dee']);
        self::assertNull($frames['pia']);
    }
}
