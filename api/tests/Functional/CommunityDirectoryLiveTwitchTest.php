<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AchievementGrant;
use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\ValueObject\Audience;
use App\Community\Domain\ValueObject\BannerPreset;
use App\Identity\Domain\Entity\User;
use App\Streaming\Application\Port\TwitchApiClientInterface;

/**
 * Le badge Live de la directory (story 30.39, issue #300).
 *
 * Les logins Twitch sont **distincts d'un test à l'autre** : le cache du check live est clé par
 * l'ensemble trié des logins et survit à la base, donc deux tests qui partageraient un login
 * partageraient aussi la réponse du premier.
 */
final class CommunityDirectoryLiveTwitchTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
    }

    public function testLiveMembersRiseToTheTopOfTheCurrentSort(): void
    {
        $alice = $this->createTwitchMember('alice@x.test', 'dirsortalice', 'alice-lt');
        $this->grantXp($alice, ['first_run', 'regular']);
        $bob = $this->createUser('bob@x.test', slug: 'bob-lt');
        $this->grantXp($bob, ['first_run']);
        // Carol est dernière au classement XP, mais elle diffuse : c'est elle qu'on vient chercher.
        $this->createTwitchMember('carol@x.test', 'dirsortcarol', 'carol-lt');

        $this->fakeLive(['dirsortcarol' => 12]);

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();

        self::assertSame(['carol-lt', 'alice-lt', 'bob-lt'], $this->slugs());
        self::assertSame('dirsortcarol', $this->rowFor('carol-lt')['liveTwitchLogin']);
        // Alice a un lien Twitch mais ne diffuse pas : elle reste triée par son XP, sans badge.
        self::assertNull($this->rowFor('alice-lt')['liveTwitchLogin']);
        self::assertNull($this->rowFor('bob-lt')['liveTwitchLogin']);
    }

    public function testLiveSortAppliesBeforePagination(): void
    {
        $alice = $this->createUser('alice@x.test', slug: 'alice-pg');
        $this->grantXp($alice, ['first_run', 'regular']);
        $bob = $this->createUser('bob@x.test', slug: 'bob-pg');
        $this->grantXp($bob, ['first_run']);
        $this->createTwitchMember('carol@x.test', 'dirpagecarol', 'carol-pg');

        $this->fakeLive(['dirpagecarol' => 3]);

        // Une page d'un seul membre : si le tri jouait après le découpage, Carol resterait en page 3.
        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp&perPage=1&page=1');
        self::assertResponseIsSuccessful();
        self::assertSame(['carol-pg'], $this->slugs());
        self::assertSame(3, $this->meta()['total']);
        self::assertSame(1, $this->meta()['perPage']);

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp&perPage=1&page=2');
        self::assertResponseIsSuccessful();
        self::assertSame(['alice-pg'], $this->slugs());

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp&perPage=1&page=3');
        self::assertResponseIsSuccessful();
        self::assertSame(['bob-pg'], $this->slugs());
    }

    public function testRecentSortAlsoRaisesLiveMembers(): void
    {
        // Le tri par activité est un second chemin : il doit remonter les live comme le tri XP.
        $this->createUser('alice@x.test', slug: 'alice-rc');
        $this->createTwitchMember('carol@x.test', 'dirrecentcarol', 'carol-rc');

        $this->fakeLive(['dirrecentcarol' => 1]);

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=recent');
        self::assertResponseIsSuccessful();
        self::assertSame('carol-rc', $this->slugs()[0]);
    }

    public function testMemberWithoutTwitchLinkCarriesNoLogin(): void
    {
        $this->createUser('alice@x.test', slug: 'alice-nl');

        $this->fakeLive([]);

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();
        self::assertNull($this->rowFor('alice-nl')['liveTwitchLogin']);
    }

    public function testTwitchOutageLeavesTheDirectoryIntact(): void
    {
        $this->createTwitchMember('alice@x.test', 'diroutagealice', 'alice-out');

        $this->fakeLive(null);

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();
        self::assertSame(['alice-out'], $this->slugs());
        self::assertNull($this->rowFor('alice-out')['liveTwitchLogin']);
    }

    public function testLiveCheckIsGroupedInASingleCall(): void
    {
        $this->createTwitchMember('alice@x.test', 'dirbatchalice', 'alice-bt');
        $this->createTwitchMember('bob@x.test', 'dirbatchbob', 'bob-bt');
        $this->createTwitchMember('carol@x.test', 'dirbatchcarol', 'carol-bt');

        $spy = $this->spyLive(['dirbatchbob' => 5]);

        $this->client->jsonRequest('GET', '/api/v1/community/directory?sort=xp');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $spy->calls, 'un appel groupé, jamais un par membre');
        self::assertCount(3, $spy->lastLogins);
        self::assertSame('bob-bt', $this->slugs()[0]);
    }

    /**
     * @param list<string> $achievementKeys
     */
    private function grantXp(User $user, array $achievementKeys): void
    {
        foreach ($achievementKeys as $key) {
            $this->entityManager->persist(AchievementGrant::grant($user->getId(), $key, $this->now));
        }
        $this->entityManager->flush();
    }

    private function createTwitchMember(string $email, string $login, string $slug): User
    {
        $user = $this->createUser($email, slug: $slug);
        $profile = CommunityProfile::create($user->getId(), $this->now);
        $profile->customize(
            null,
            null,
            null,
            null,
            BannerPreset::DEFAULT,
            null,
            [['label' => 'Twitch', 'url' => 'https://twitch.tv/'.$login]],
            [],
            Audience::MEMBERS,
            [],
            $this->now,
        );
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, int>|null $live
     */
    private function fakeLive(?array $live): void
    {
        $this->spyLive($live);
    }

    /**
     * @param array<string, int>|null $live
     */
    private function spyLive(?array $live): TwitchSpy
    {
        // Sans cela, le noyau redémarre entre deux requêtes et le double retombe sur le vrai client
        // Twitch : la deuxième page d'un même test ne verrait plus personne en direct.
        $this->client->disableReboot();

        $spy = new TwitchSpy($live);
        self::getContainer()->set(TwitchApiClientInterface::class, $spy);

        return $spy;
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        $slugs = [];
        foreach ($this->data() as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['slug']);
            $slugs[] = $row['slug'];
        }

        return $slugs;
    }

    /**
     * @return array<mixed>
     */
    private function rowFor(string $slug): array
    {
        foreach ($this->data() as $row) {
            self::assertIsArray($row);
            if ($slug === $row['slug']) {
                return $row;
            }
        }

        self::fail(sprintf('aucune ligne pour %s', $slug));
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

    /**
     * @return array<mixed>
     */
    private function meta(): array
    {
        $meta = $this->decodedJsonResponse()['meta'] ?? null;
        self::assertIsArray($meta);

        return $meta;
    }
}

/**
 * Compte les appels : l'AC 8 porte autant sur le nombre d'appels que sur le résultat.
 */
final class TwitchSpy implements TwitchApiClientInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $lastLogins = [];

    /**
     * @param array<string, int>|null $live
     */
    public function __construct(private readonly ?array $live)
    {
    }

    public function fetchViewerCount(): ?int
    {
        return null;
    }

    public function fetchLiveLogins(array $logins): ?array
    {
        ++$this->calls;
        $this->lastLogins = $logins;

        if (null === $this->live) {
            return null;
        }

        return array_intersect_key($this->live, array_flip($logins));
    }

    public function fetchAvatars(array $logins): array
    {
        return [];
    }
}
