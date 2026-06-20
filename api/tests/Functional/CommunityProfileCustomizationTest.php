<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\CommunityProfile;

final class CommunityProfileCustomizationTest extends FunctionalTestCase
{
    public function testRetiredShowcaseKeyIsStrippedOnRead(): void
    {
        $user = $this->createUser('ivy@example.org', slug: 'ivy');
        $now = new \DateTimeImmutable();
        // Legacy layout holding a since-retired widget key (bypasses the write-path validation).
        $profile = new CommunityProfile(
            bin2hex(random_bytes(16)),
            $user->getId(),
            $now,
            $now,
            audience: 'public',
            showcaseLayout: ['featured_achievements', 'best_runs'],
        );
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/ivy');
        self::assertResponseIsSuccessful();
        $customization = $this->data()['customization'] ?? null;
        self::assertIsArray($customization);
        self::assertSame(['best_runs'], $customization['showcaseLayout'], 'retired widget keys are filtered on read');
    }

    public function testOwnerCanCustomizeAndReadBack(): void
    {
        $user = $this->createUser('dave@example.org', slug: 'dave', displayName: 'Dave');
        $game = $this->createGame('Celeste', 'celeste');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'bio' => 'Speedrunner.',
            'tagline' => 'gg ez',
            'pronouns' => 'they/them',
            'bannerPreset' => 'sunset',
            'audience' => 'public',
            'socialLinks' => [['label' => 'Twitch', 'url' => 'https://twitch.tv/dave']],
            'favoriteGameIds' => [$game->getId()],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame('Speedrunner.', $data['bio']);
        self::assertSame('sunset', $data['bannerPreset']);
        self::assertSame('public', $data['audience']);
        $favorites = $data['favoriteGames'] ?? null;
        self::assertIsArray($favorites);
        self::assertCount(1, $favorites);
    }

    public function testPublicAudienceShowsCustomizationToAnonymous(): void
    {
        $user = $this->createUser('erin@example.org', slug: 'erin');
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'bio' => 'Hello world.',
            'audience' => 'public',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/erin');
        self::assertResponseIsSuccessful();
        $data = $this->data();
        $customization = $data['customization'] ?? null;
        self::assertIsArray($customization);
        self::assertSame('Hello world.', $customization['bio']);
    }

    public function testMembersAudienceHidesCustomizationFromAnonymous(): void
    {
        $user = $this->createUser('frank@example.org', slug: 'frank');
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'bio' => 'Secret-ish.',
            'audience' => 'members',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/frank');
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertNull($data['customization'], 'members-only customization is hidden from anonymous');
        // Core profile stays public.
        self::assertSame('frank', $data['slug']);
        self::assertIsArray($data['stats']);
    }

    public function testShowcaseLayoutIsSavedDedupedAndFiltered(): void
    {
        $user = $this->createUser('jade@example.org', slug: 'jade');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'showcaseLayout' => ['favorite_games', 'best_runs', 'bogus_widget', 'favorite_games'],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame(['favorite_games', 'best_runs'], $data['showcaseLayout']);
    }

    public function testInvalidAudienceIsRejected(): void
    {
        $user = $this->createUser('gwen@example.org', slug: 'gwen');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'everyone']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownFavoriteGameIsRejected(): void
    {
        $user = $this->createUser('hugo@example.org', slug: 'hugo');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['favoriteGameIds' => ['does-not-exist']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidSocialLinkIsRejected(): void
    {
        $user = $this->createUser('iris@example.org', slug: 'iris');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'socialLinks' => [['label' => 'Bad', 'url' => 'javascript:alert(1)']],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateRequiresAuthentication(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bio' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAvatarFrameRoundTripAndValidation(): void
    {
        $user = $this->createUser('hank@example.org', slug: 'hank');
        $this->loginAs($user);

        // A valid frame round-trips and surfaces on the public profile customization.
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['avatarFrame' => 'holographic', 'audience' => 'public']);
        self::assertResponseIsSuccessful();
        self::assertSame('holographic', $this->data()['avatarFrame']);

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/hank');
        $customization = $this->data()['customization'] ?? null;
        self::assertIsArray($customization);
        self::assertSame('holographic', $customization['avatarFrame']);

        // An unknown frame is rejected.
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['avatarFrame' => 'bogus_frame']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDisplayNameOverrideReplacesAccountNameButKeepsSlug(): void
    {
        $user = $this->createUser('gwen@example.org', slug: 'gwen', displayName: 'gwen');
        $this->loginAs($user);

        // No override yet: the editor echoes the account name + a null override.
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame('gwen', $this->data()['accountName']);
        self::assertNull($this->data()['displayName']);

        // Set an override.
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['displayName' => 'Gwendoline']);
        self::assertResponseIsSuccessful();
        self::assertSame('Gwendoline', $this->data()['displayName']);

        // The public profile now shows the override; the URL slug is unchanged.
        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/gwen');
        self::assertSame('Gwendoline', $this->data()['displayName']);
        self::assertSame('gwen', $this->data()['slug']);

        // Clearing it falls back to the account name.
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['displayName' => '']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->data()['displayName']);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/gwen');
        self::assertSame('gwen', $this->data()['displayName']);
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
