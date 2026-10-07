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

    public function testTheAdminListsOnlyShopCosmeticsAndPausesItems(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $itemId = $this->item('frame', 'comet', 10);
        $this->loginAs($admin);

        // A free frame cannot be sold.
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'frame', 'cosmeticKey' => 'gold', 'price' => 50]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', sprintf('/api/v1/admin/shop/items/%s/pause', $itemId));
        self::assertResponseStatusCodeSame(204);
        self::assertSame('paused', $this->adminItem($itemId)['status'] ?? null);
        $this->loginAs($this->member);
        $this->client->request('GET', '/api/v1/shop');
        self::assertSame([], $this->decodedJsonResponse()['items'] ?? null);

        $this->loginAs($admin);
        $this->client->request('POST', sprintf('/api/v1/admin/shop/items/%s/resume', $itemId));
        self::assertResponseStatusCodeSame(204);
        self::assertSame('on_sale', $this->adminItem($itemId)['status'] ?? null);

        $this->loginAs($this->member);
        $this->client->request('GET', '/api/v1/admin/shop/items');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', sprintf('/api/v1/admin/shop/items/%s', $itemId));
        self::assertResponseStatusCodeSame(403);
    }

    public function testVisitorsSeeTheShopWindow(): void
    {
        $this->item('frame', 'comet', 10);

        $this->client->request('GET', '/api/v1/shop');

        self::assertResponseIsSuccessful();
        $items = $this->decodedJsonResponse()['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertFalse($first['owned'] ?? null);
        self::assertSame('2026-10-01T10:00:00+00:00', $first['listedAt'] ?? null);
    }

    public function testTheAdminChangesThePriceAndWindow(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $itemId = $this->item('frame', 'comet', 10);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/shop/items/%s', $itemId), ['price' => 25, 'availableFrom' => null, 'availableUntil' => '2099-01-01T00:00:00+00:00']);
        self::assertResponseStatusCodeSame(204);
        $item = $this->adminItem($itemId);
        self::assertSame(25, $item['price'] ?? null);
        self::assertSame('2099-01-01T00:00:00+00:00', $item['availableUntil'] ?? null);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/shop/items/%s', $itemId), ['price' => 0]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletingAnItemKeepsWhatWasBought(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $itemId = $this->item('banner', 'starfield', 30);
        $this->gold($this->member, 100);
        $this->loginAs($this->member);
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));

        $this->loginAs($admin);
        $item = $this->adminItem($itemId);
        self::assertSame(1, $item['sales'] ?? null);
        self::assertSame(30, $item['pelles'] ?? null);

        $this->client->request('DELETE', sprintf('/api/v1/admin/shop/items/%s', $itemId));
        self::assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(ShopItem::class, $itemId), 'deleted for good');
        self::assertCount(1, $this->entityManager->getRepository(OwnedCosmetic::class)->findAll());
        self::assertSame(70, $this->balance());
        $this->client->request('DELETE', sprintf('/api/v1/admin/shop/items/%s', $itemId));
        self::assertResponseStatusCodeSame(404);
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

    public function testAPurchaseDuringAPromotionCostsThePromotionalPrice(): void
    {
        $itemId = $this->item('frame', 'comet', 80);
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/shop/items/%s/promotion', $itemId), [
            'price' => 60,
            'endsAt' => new \DateTimeImmutable('+2 days')->format(\DATE_ATOM),
        ]);
        self::assertResponseStatusCodeSame(204);
        $promotion = $this->adminItem($itemId)['promotion'] ?? null;
        self::assertIsArray($promotion);
        self::assertSame('running', $promotion['status']);

        // The shop shows both prices and the discount.
        $this->client->request('GET', '/api/v1/shop');
        $listed = $this->firstShopItem();
        self::assertSame(60, $listed['price']);
        self::assertSame(80, $listed['regularPrice']);
        self::assertIsArray($listed['promotion']);
        self::assertSame(25, $listed['promotion']['percent']);

        $this->gold($this->member, 100);
        $this->loginAs($this->member);
        $this->client->jsonRequest('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId), ['expectedPrice' => 60]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(40, $this->balance());
    }

    public function testAPriceThatChangedSinceThePageWasShownIsNotCharged(): void
    {
        // The page showed 60 (a promotion since over, or a stale page): the regular 80 is not taken without a word.
        $itemId = $this->item('frame', 'comet', 80);
        $this->gold($this->member, 100);
        $this->loginAs($this->member);

        $this->client->jsonRequest('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId), ['expectedPrice' => 60]);

        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('price_changed', $error['code'] ?? null);
        self::assertSame(100, $this->balance());
    }

    public function testAPromotionAlreadyOverCannotBeSaved(): void
    {
        $itemId = $this->item('frame', 'comet', 80);
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/shop/items/%s/promotion', $itemId), [
            'price' => 60,
            'endsAt' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAPromotionNotStartedYetOrEndedChargesTheRegularPrice(): void
    {
        $upcoming = $this->item('frame', 'comet', 80);
        $item = $this->entityManager->find(ShopItem::class, $upcoming);
        self::assertInstanceOf(ShopItem::class, $item);
        $item->promote(40, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+3 days'), new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->gold($this->member, 100);
        $this->loginAs($this->member);
        $this->client->request('GET', '/api/v1/shop');
        $listed = $this->firstShopItem();
        self::assertNull($listed['promotion']);

        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $upcoming));
        self::assertResponseStatusCodeSame(204);
        self::assertSame(20, $this->balance());
    }

    public function testAPromotionMustStayUnderTheRegularPriceAndEnd(): void
    {
        $itemId = $this->item('frame', 'comet', 80);
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $end = new \DateTimeImmutable('+2 days')->format(\DATE_ATOM);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/shop/items/%s/promotion', $itemId), ['price' => 80, 'endsAt' => $end]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/shop/items/%s/promotion', $itemId), ['price' => 60]);
        self::assertResponseStatusCodeSame(422);

        // With a promotion set, the regular price cannot drop to it; once taken down, it can.
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/shop/items/%s/promotion', $itemId), ['price' => 60, 'endsAt' => $end]);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/shop/items/%s', $itemId), ['price' => 50]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/shop/items/%s/promotion', $itemId));
        self::assertResponseStatusCodeSame(204);
        $listed = $this->adminItem($itemId);
        self::assertArrayHasKey('promotion', $listed);
        self::assertNull($listed['promotion']);
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/shop/items/%s', $itemId), ['price' => 50]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testTheHelloAssoShopBannerShowsWhileItRuns(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->client->request('GET', '/api/v1/shop/announcement');
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertNull($response['data']);

        $this->loginAs($admin);
        $this->client->jsonRequest('PUT', '/api/v1/admin/shop/announcement', ['message' => 'Sweats à -20 % !', 'endsAt' => new \DateTimeImmutable('+3 days')->format(\DATE_ATOM)]);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('PUT', '/api/v1/admin/shop/announcement', ['message' => 'Trop tard', 'endsAt' => new \DateTimeImmutable('-1 day')->format(\DATE_ATOM)]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/v1/shop/announcement');
        $banner = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($banner);
        self::assertSame('Sweats à -20 % !', $banner['message']);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/shop/announcement');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/shop/announcement');
        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertNull($response['data']);

        $this->loginAs($this->member);
        $this->client->jsonRequest('PUT', '/api/v1/admin/shop/announcement', ['message' => 'x', 'endsAt' => new \DateTimeImmutable('+3 days')->format(\DATE_ATOM)]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The first item of the member's shop (story 41.14 tests list a single one).
     *
     * @return array<mixed>
     */
    private function firstShopItem(): array
    {
        $items = $this->decodedJsonResponse()['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);

        return $first;
    }

    private function item(string $type, string $key, int $price, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): string
    {
        $item = ShopItem::list($type, $key, $price, $from, $until, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item->getId();
    }

    /**
     * @return array<mixed>
     */
    private function adminItem(string $itemId): array
    {
        $this->client->request('GET', '/api/v1/admin/shop/items');
        $items = $this->decodedJsonResponse()['items'] ?? null;
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (is_array($item) && $itemId === ($item['id'] ?? null)) {
                return $item;
            }
        }
        self::fail('item not listed');
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
