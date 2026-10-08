<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Entity\AchievementGrant;
use App\Community\Domain\ValueObject\CosmeticReward;

/** Story 30.52: the collections of achievements, from the admin to the catalogue and the completion reward. */
final class AchievementCollectionTest extends FunctionalTestCase
{
    public function testTheAdminCreatesFillsOrdersAndDeletesCollections(): void
    {
        $first = $this->definition('first_run', 1);
        $second = $this->definition('regular', 2);
        $this->entityManager->flush();
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-collections', ['name' => 'Re:Zero', 'description' => 'La sorcière.', 'secret' => true, 'reward' => ['type' => 'color', 'key' => 'emerald'], 'pelles' => 50]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->dataArray();
        self::assertSame('Re:Zero', $created['name']);
        self::assertTrue($created['secret']);
        self::assertSame(50, $created['pelles']);
        $reZero = $created['id'];
        self::assertIsString($reZero);

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-collections', ['name' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-collections', ['name' => 'LAN']);
        self::assertResponseStatusCodeSame(201);
        $lan = $this->dataArray()['id'];
        self::assertIsString($lan);

        // An achievement goes into a collection from its form, and from a drop in the list.
        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievements/'.$first->getId(), ['name' => 'Première', 'rule' => $first->getRule(), 'collectionId' => $reZero]);
        self::assertResponseIsSuccessful();
        self::assertSame($reZero, $this->dataArray()['collectionId']);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/reorder', ['ids' => [$second->getId(), $first->getId()], 'collections' => [$second->getId() => $lan]]);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/reorder', ['ids' => [$second->getId()], 'collections' => [$second->getId() => 'nope']]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-collections/reorder', ['ids' => [$lan, $reZero]]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievements');
        self::assertResponseIsSuccessful();
        $json = $this->decodedJsonResponse();
        self::assertIsArray($json['meta']);
        self::assertIsArray($json['meta']['collections']);
        self::assertSame(['LAN', 'Re:Zero'], array_map(static fn (mixed $c): mixed => is_array($c) ? $c['name'] : null, $json['meta']['collections']));
        self::assertSame([$second->getId() => $lan, $first->getId() => $reZero], $this->collectionsOf($json['data']));

        // Deleting a collection sends its achievements back to « Autres succès ».
        $this->client->jsonRequest('DELETE', '/api/v1/admin/community/achievement-collections/'.$lan);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievements');
        self::assertSame([$second->getId() => null, $first->getId() => $reZero], $this->collectionsOf($this->decodedJsonResponse()['data']));
    }

    public function testTheCatalogueShowsTheProgressAndHidesASecretCollectionUntilAFirstUnlock(): void
    {
        $collection = $this->collection('Re:Zero', secret: true);
        $this->definition('witch_of_envy', 1, $collection);
        $this->definition('return_by_death', 2, $collection);
        $this->definition('first_run', 3);
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/achievements');
        self::assertResponseIsSuccessful();
        $data = $this->dataArray();
        self::assertSame([], $data['collections']);
        self::assertSame(['first_run'], $this->keysOf($data['achievements']));

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        $profile = $this->dataArray();
        self::assertSame(['unlocked' => 0, 'total' => 1], $profile['achievementStats']);

        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'witch_of_envy', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/achievements');
        $data = $this->dataArray();
        self::assertSame(['witch_of_envy', 'return_by_death', 'first_run'], $this->keysOf($data['achievements']));
        self::assertIsArray($data['collections']);
        $reZero = $data['collections'][0] ?? null;
        self::assertIsArray($reZero);
        self::assertSame(['Re:Zero', true, 1, 2, false], [$reZero['name'], $reZero['secret'], $reZero['unlocked'], $reZero['total'], $reZero['complete']]);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        $profile = $this->dataArray();
        self::assertSame(['unlocked' => 1, 'total' => 3], $profile['achievementStats']);
        self::assertIsArray($profile['collections']);
        self::assertCount(1, $profile['collections']);
    }

    public function testCompletingACollectionGivesItsRewardOnce(): void
    {
        $collection = $this->collection('Re:Zero');
        $collection->rewardWith(CosmeticReward::fromParts('color', 'emerald'), 50, new \DateTimeImmutable());
        $this->definition('witch_of_envy', 1, $collection);
        $last = $this->definition('return_by_death', 2, $collection);
        // An inactive achievement never counts: the collection completes without it.
        $this->definition('retired', 3, $collection)->deactivate(new \DateTimeImmutable());
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'witch_of_envy', new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$last->getId().'/grants', ['slug' => 'alice']);
        self::assertResponseStatusCodeSame(201);
        // Granted again (revoked, then given back): nothing twice.
        $this->client->jsonRequest('DELETE', '/api/v1/admin/community/achievements/'.$last->getId().'/grants/alice');
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$last->getId().'/grants', ['slug' => 'alice']);
        self::assertResponseStatusCodeSame(201);

        $connection = $this->entityManager->getConnection();
        $params = ['user' => $alice->getId()];
        self::assertSame(1, $this->number($connection->fetchOne('SELECT COUNT(*) FROM community_achievement_collection_completion WHERE user_id = :user', $params)));
        self::assertSame(50, $this->number($connection->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE user_id = :user AND reason = 'collection_reward'", $params)));
        self::assertSame('collection', $connection->fetchOne("SELECT source FROM owned_cosmetic WHERE user_id = :user AND cosmetic_key = 'emerald'", $params));
        $notifications = $connection->fetchAllAssociative("SELECT payload FROM community_notification WHERE recipient_id = :user AND type = 'collection_completed'", $params);
        self::assertCount(1, $notifications);
        $payload = json_decode(is_string($notifications[0]['payload'] ?? null) ? $notifications[0]['payload'] : '', true);
        self::assertIsArray($payload);
        self::assertSame(['Re:Zero', 50, 'Couleur de pseudo « Émeraude »'], [$payload['name'], $payload['pelles'], $payload['cosmetic']]);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/achievements');
        $data = $this->dataArray();
        self::assertIsArray($data['collections']);
        $reZero = $data['collections'][0] ?? null;
        self::assertIsArray($reZero);
        self::assertSame([2, 2, true], [$reZero['unlocked'], $reZero['total'], $reZero['complete']]);
    }

    private function number(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    private function collection(string $name, bool $secret = false): AchievementCollection
    {
        $collection = AchievementCollection::create($name, '', 0, new \DateTimeImmutable());
        $collection->markSecret($secret, new \DateTimeImmutable());
        $this->entityManager->persist($collection);

        return $collection;
    }

    private function definition(string $key, int $position, ?AchievementCollection $collection = null): AchievementDefinition
    {
        $now = new \DateTimeImmutable();
        $definition = AchievementDefinition::create($key, ucfirst($key), '', ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1000]]], $position, $now);
        $definition->moveToCollection($collection?->getId(), $now);
        $this->entityManager->persist($definition);

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataArray(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function keysOf(mixed $achievements): array
    {
        self::assertIsArray($achievements);

        return array_values(array_map(static fn (mixed $a): string => is_array($a) && is_string($a['key'] ?? null) ? $a['key'] : '', $achievements));
    }

    /**
     * @return array<string, mixed> achievement id => collection id
     */
    private function collectionsOf(mixed $definitions): array
    {
        self::assertIsArray($definitions);
        $out = [];
        foreach ($definitions as $definition) {
            self::assertIsArray($definition);
            self::assertIsString($definition['id'] ?? null);
            $out[$definition['id']] = $definition['collectionId'] ?? null;
        }

        return $out;
    }
}
