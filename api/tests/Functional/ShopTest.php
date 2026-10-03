<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.7: the cosmetics shop. The code catalog sells nothing yet (no member-drawn visual), so the items here are
 * listed straight in the repository, the way the admin would once a shop cosmetic exists.
 */
final class ShopTest extends FunctionalTestCase
{
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
    }

    public function testAMemberBuysACosmeticOnSale(): void
    {
        $itemId = $this->item('frame', 'comet', 80);
        $this->gold($this->member, 100);
        $this->loginAs($this->member);

        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(20, $this->balance());
        $owned = $this->entityManager->getRepository(OwnedCosmetic::class)->findAll();
        self::assertCount(1, $owned);
        self::assertSame('comet', $owned[0]->getCosmeticKey());

        $this->client->request('GET', '/api/v1/shop');
        $items = $this->decodedJsonResponse()['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertTrue($first['owned'] ?? null);
    }

    public function testBuyingTwiceIsRefused(): void
    {
        $itemId = $this->item('frame', 'comet', 30);
        $this->gold($this->member, 100);
        $this->loginAs($this->member);

        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(70, $this->balance());
    }

    public function testNotEnoughPellesIsRefused(): void
    {
        $itemId = $this->item('banner', 'starfield', 80);
        $this->gold($this->member, 50);
        $this->loginAs($this->member);

        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(OwnedCosmetic::class)->findAll());
    }

    public function testASeasonalItemSellsOnlyInItsWindow(): void
    {
        $past = $this->item('frame', 'comet', 10, new \DateTimeImmutable('2020-01-01T00:00:00+00:00'), new \DateTimeImmutable('2020-02-01T00:00:00+00:00'));
        $upcoming = $this->item('frame', 'aurora_ring', 10, new \DateTimeImmutable('2099-01-01T00:00:00+00:00'), null);
        $this->gold($this->member, 100);
        $this->loginAs($this->member);

        $this->client->request('GET', '/api/v1/shop');
        self::assertSame([], $this->decodedJsonResponse()['items'] ?? null);

        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $past));
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $upcoming));
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheAdminListsOnlyShopCosmeticsAndRetiresItems(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $itemId = $this->item('frame', 'comet', 10);
        $this->loginAs($admin);

        // A free frame cannot be sold.
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'frame', 'cosmeticKey' => 'gold', 'price' => 50]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('DELETE', sprintf('/api/v1/admin/shop/items/%s', $itemId));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/admin/shop/items');
        $body = $this->decodedJsonResponse();
        self::assertSame(['frame' => [], 'banner' => []], $body['sellable'] ?? null);
        $items = $body['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('retired', $first['status'] ?? null);

        $this->client->request('GET', '/api/v1/admin/shop/items');
        $this->loginAs($this->member);
        $this->client->request('GET', '/api/v1/admin/shop/items');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheProfileEditorListsTheCosmeticsBought(): void
    {
        $itemId = $this->item('banner', 'starfield', 10);
        $this->gold($this->member, 100);
        $this->loginAs($this->member);
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));

        $this->client->request('GET', '/api/v1/community/profile');

        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        $data = $body['data'] ?? $body;
        self::assertIsArray($data);
        self::assertSame(['starfield'], $data['ownedBanners'] ?? null);
        self::assertSame([], $data['ownedFrames'] ?? null);
    }

    private function item(string $type, string $key, int $price, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): string
    {
        $item = ShopItem::list($type, $key, $price, $from, $until, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item->getId();
    }

    private function gold(User $member, int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    private function balance(): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $this->member->getId(), 'kind' => PelleKind::Gold]),
        ));
    }
}
