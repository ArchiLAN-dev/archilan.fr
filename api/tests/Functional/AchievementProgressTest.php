<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Entity\AchievementGrant;

/** Story 30.53: where the member stands on one of their achievements, asked on demand. */
final class AchievementProgressTest extends FunctionalTestCase
{
    private const string URL = '/api/v1/community/profile/achievements/%s/progress';

    public function testTheMemberSeesEachConditionOfAnAchievementTheyDoNotHaveYet(): void
    {
        $this->definition('regular', ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 10], ['fact' => 'itemsFromOthers', 'operator' => '>=', 'value' => 3000]]]);
        $this->client->jsonRequest('GET', sprintf(self::URL, 'regular'));
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('alice@example.org', slug: 'alice'));
        $this->client->jsonRequest('GET', sprintf(self::URL, 'regular'));
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertFalse($data['unlocked']);
        $progress = $data['progress'] ?? null;
        self::assertIsArray($progress);
        self::assertSame(['group', 'all', false], [$progress['type'], $progress['op'], $progress['met']]);
        self::assertIsArray($progress['rules']);
        $items = $progress['rules'][1] ?? null;
        self::assertIsArray($items);
        self::assertSame(['itemsFromOthers', 0, 3000, false], [$items['fact'], $items['current'], $items['value'], $items['met']]);
        self::assertSame('Items reçus d\'autres joueurs (hors release et collect)', $items['label']);
    }

    public function testAnAchievementHeldShowsWhenAndWhetherTheTeamGaveIt(): void
    {
        $definition = $this->definition('regular');
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$definition->getId().'/grants', ['slug' => 'alice']);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', sprintf(self::URL, 'regular'));
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertTrue($data['unlocked']);
        self::assertTrue($data['byTeam']);
        self::assertNull($data['progress']);
        self::assertIsString($data['unlockedAt']);
    }

    public function testAHiddenAchievementDoesNotExist(): void
    {
        $collection = AchievementCollection::create('Re:Zero', '', 0, new \DateTimeImmutable());
        $collection->markSecret(true, new \DateTimeImmutable());
        $this->entityManager->persist($collection);
        $this->definition('witch_of_envy', collectionId: $collection->getId());
        $this->definition('return_by_death', collectionId: $collection->getId());
        $this->definition('retired')->deactivate(new \DateTimeImmutable());
        $this->entityManager->flush();
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        foreach (['witch_of_envy', 'retired', 'unknown'] as $key) {
            $this->client->jsonRequest('GET', sprintf(self::URL, $key));
            self::assertResponseStatusCodeSame(404);
        }

        // Once a first achievement of the secret collection is unlocked, the others show.
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'return_by_death', new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->client->jsonRequest('GET', sprintf(self::URL, 'witch_of_envy'));
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $rule
     */
    private function definition(string $key, ?array $rule = null, ?string $collectionId = null): AchievementDefinition
    {
        $now = new \DateTimeImmutable();
        $definition = AchievementDefinition::create($key, ucfirst($key), '', $rule ?? ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 10]]], 1, $now);
        $definition->moveToCollection($collectionId, $now);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
