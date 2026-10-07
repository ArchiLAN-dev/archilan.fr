<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Story 41.29: « Ma collection » - every cosmetic, what the member owns and where from, what they may wear, and how
 * to get the rest.
 */
final class MyCollectionTest extends FunctionalTestCase
{
    public function testTheCollectionSaysWhatIsOwnedWearableOrHowToGetIt(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        foreach ([['pionnier', 'Pionnier', 'free'], ['ancien', 'Ancien', 'members'], ['chasseur', 'Chasseur', 'shop'], ['phil', 'Phil Connors', 'reward'], ['bientot', 'Bientôt', 'reward']] as [$key, $label, $access]) {
            $this->client->jsonRequest('POST', '/api/v1/admin/profile-titles', ['key' => $key, 'label' => $label, 'access' => $access, 'rarity' => 'phil' === $key ? 'epic' : 'common']);
            self::assertResponseStatusCodeSame(201);
        }
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'title', 'cosmeticKey' => 'chasseur', 'price' => 120]);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', [
            'key' => 'jour_sans_fin',
            'name' => 'Un jour sans fin',
            'description' => '4 semaines de coffre d\'affilée.',
            'rule' => ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1000]]],
            'reward' => ['type' => 'title', 'key' => 'phil'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->decodedJsonResponse();
        $achievementId = is_array($created['data'] ?? null) ? ($created['data']['id'] ?? null) : null;
        self::assertIsString($achievementId);

        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member', slug: 'mem');
        $this->loginAs($member);
        $items = $this->collection();
        self::assertSame('available', $items['title:pionnier']['status'] ?? null);
        self::assertSame([['kind' => 'members', 'label' => 'Réservé aux adhérents', 'detail' => null, 'price' => null]], $items['title:ancien']['unlock'] ?? null);
        self::assertSame([['kind' => 'shop', 'label' => 'En boutique', 'detail' => null, 'price' => 120]], $items['title:chasseur']['unlock'] ?? null);
        self::assertSame([['kind' => 'achievement', 'label' => 'Succès « Un jour sans fin »', 'detail' => '4 semaines de coffre d\'affilée.', 'price' => null]], $items['title:phil']['unlock'] ?? null);
        self::assertSame('epic', $items['title:phil']['rarity'] ?? null);
        self::assertSame('locked', $items['title:bientot']['status'] ?? null);
        $soon = $items['title:bientot']['unlock'] ?? null;
        self::assertIsArray($soon);
        self::assertIsArray($soon[0] ?? null);
        self::assertSame('reward', $soon[0]['kind'] ?? null, 'a reward nothing unlocks yet');
        self::assertSame([['kind' => 'shop', 'label' => 'Pas encore en vente', 'detail' => null, 'price' => null]], $items['color:ruby']['unlock'] ?? null);

        $this->loginAs($this->createUser('admin2@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$achievementId.'/grants', ['slug' => 'mem']);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($member);
        $items = $this->collection();
        self::assertSame('owned', $items['title:phil']['status'] ?? null);
        self::assertSame([], $items['title:phil']['unlock'] ?? null);
        $origin = $items['title:phil']['origin'] ?? null;
        self::assertIsArray($origin);
        self::assertSame('achievement', $origin['source'] ?? null);
        self::assertSame('Un jour sans fin', $origin['label'] ?? null);
    }

    public function testTheCollectionIsTheMembersOwn(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/me/collection');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<string, array<mixed>> keyed `{type}:{key}`
     */
    private function collection(): array
    {
        $this->client->jsonRequest('GET', '/api/v1/me/collection');
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertIsInt($body['owned'] ?? null);
        self::assertIsArray($body['items'] ?? null);
        $items = [];
        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['type'] ?? null);
            self::assertIsString($item['key'] ?? null);
            $items[$item['type'].':'.$item['key']] = $item;
        }

        return $items;
    }
}
