<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.23: a colour bought for the name, shown where no rarity colour (30.44) applies - on the profile and on
 * the cards, through the same `nameStyle`.
 */
final class NameColorTest extends FunctionalTestCase
{
    public function testAMemberBuysAColourAndTheirNameWearsIt(): void
    {
        $itemId = $this->listColor('emerald');
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'color', 'cosmeticKey' => 'gold', 'price' => 50]);
        self::assertResponseStatusCodeSame(422, 'only a colour of the palette sells');

        $player = $this->createUser('pla@example.org', slug: 'pla');
        $this->loginAs($player);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['nameColor' => 'emerald']);
        self::assertResponseStatusCodeSame(422, 'not bought yet');

        $this->gold($player, 100);
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['nameColor' => 'emerald', 'audience' => 'public']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['nameColor' => 'ruby']);
        self::assertResponseStatusCodeSame(422, 'a colour not bought');

        $editable = $this->data('/api/v1/community/profile');
        self::assertSame('emerald', $editable['nameColor'] ?? null);
        self::assertSame(['emerald'], $editable['ownedColors'] ?? null);
        self::assertSame('color-emerald', $this->data('/api/v1/community/profiles/pla')['nameStyle'] ?? null);

        // On the cards too: the comment author is a card.
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/pla/comments', ['body' => 'Salut']);
        self::assertResponseIsSuccessful();
        $comments = $this->data('/api/v1/community/profiles/pla/comments');
        self::assertIsArray($comments[0] ?? null);
        self::assertIsArray($comments[0]['author'] ?? null);
        self::assertSame('color-emerald', $comments[0]['author']['nameStyle'] ?? null);
    }

    public function testTheRarityColourComesFirstUnlessTheTitledNameIsOff(): void
    {
        $itemId = $this->listColor('azure');
        $member = $this->createUser('mem@example.org', slug: 'mem');
        $from = new \DateTimeImmutable('-1 month');
        $this->entityManager->persist(Membership::create($member->getId(), $from, new \DateTimeImmutable('+11 months'), 'admin', null, null, $from));
        $this->entityManager->flush();
        $this->loginAs($member);
        $this->gold($member, 100);
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['nameColor' => 'azure']);
        self::assertResponseIsSuccessful();

        self::assertSame('epic', $this->nameStyleOf('mem'), 'the member\'s rarity comes first');

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['titledName' => false]);
        self::assertSame('color-azure', $this->nameStyleOf('mem'), 'titled name off: the colour bought shows');

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['nameColor' => '']);
        self::assertNull($this->nameStyleOf('mem'));
    }

    /** @phpstan-impure */
    private function nameStyleOf(string $slug): ?string
    {
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/'.$slug);
        $profile = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($profile);
        self::assertArrayHasKey('nameStyle', $profile);
        $style = $profile['nameStyle'];
        self::assertTrue(null === $style || is_string($style));

        return $style;
    }

    private function listColor(string $key): string
    {
        $this->loginAs($this->createUser('admin-'.$key.'@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'color', 'cosmeticKey' => $key, 'price' => 80]);
        self::assertResponseStatusCodeSame(201);
        $listed = $this->decodedJsonResponse();
        self::assertIsString($listed['id'] ?? null);

        return $listed['id'];
    }

    private function gold(User $member, int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    /**
     * Each call is a new request: never the same answer twice.
     *
     * @phpstan-impure
     *
     * @return array<mixed>
     */
    private function data(string $url): array
    {
        $this->client->jsonRequest('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
