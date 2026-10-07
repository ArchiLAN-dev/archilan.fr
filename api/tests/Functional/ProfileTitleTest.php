<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.22: profile titles the admins write, sold in the shop and worn under the name.
 */
final class ProfileTitleTest extends FunctionalTestCase
{
    public function testTheAdminWritesTitlesAndTheSiteListsTheWearableOnes(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        $this->write('chasseur', 'Chasseur de goals', 'shop');
        $this->write('ancien', 'Ancien', 'members');
        foreach ([['key' => 'chasseur', 'label' => 'Doublon', 'access' => 'free'], ['key' => 'X!', 'label' => 'Clé', 'access' => 'free'], ['key' => 'court', 'label' => 'A', 'access' => 'free'], ['key' => 'acces', 'label' => 'Accès', 'access' => 'vip']] as $payload) {
            $this->client->jsonRequest('POST', '/api/v1/admin/profile-titles', $payload);
            self::assertContains($this->client->getResponse()->getStatusCode(), [409, 422], (string) json_encode($payload));
        }

        $this->client->jsonRequest('PATCH', '/api/v1/admin/profile-titles/ancien', ['label' => 'Vieux de la vieille']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/api/v1/admin/profile-titles/ancien/retire');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/profile-titles');
        self::assertResponseIsSuccessful();
        $published = $this->decodedJsonResponse();
        self::assertSame([['key' => 'chasseur', 'label' => 'Chasseur de goals', 'access' => 'shop', 'rarity' => 'common', 'icon' => null]], $published['titles'] ?? null, 'a retired title is not listed');

        $this->client->request('GET', '/api/v1/admin/profile-titles');
        $admin = $this->decodedJsonResponse();
        $titles = $admin['titles'] ?? null;
        self::assertIsArray($titles);
        self::assertCount(2, $titles);
        self::assertIsArray($titles[1] ?? null);
        self::assertSame(['key' => 'ancien', 'label' => 'Vieux de la vieille', 'access' => 'members', 'retired' => true, 'position' => 1, 'rarity' => 'common', 'icon' => null], $titles[1]);

        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'title', 'cosmeticKey' => 'chasseur', 'price' => 120]);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'title', 'cosmeticKey' => 'ancien', 'price' => 120]);
        self::assertResponseStatusCodeSame(422, 'only a shop title sells');
    }

    public function testAMemberBuysATitleThenWearsItUnderTheirName(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->write('chasseur', 'Chasseur de goals', 'shop');
        $this->write('equipe', 'Équipe', 'admins');
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'title', 'cosmeticKey' => 'chasseur', 'price' => 120]);
        $itemId = $this->decodedJsonResponse()['id'] ?? null;
        self::assertIsString($itemId);

        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member', slug: 'mem');
        $this->loginAs($member);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => 'chasseur']);
        self::assertResponseStatusCodeSame(422, 'not bought yet');
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => 'equipe']);
        self::assertResponseStatusCodeSame(422, 'admins only');

        $this->gold($member, 200);
        $this->client->request('POST', sprintf('/api/v1/shop/items/%s/buy', $itemId));
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => 'chasseur']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        $editable = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($editable);
        self::assertSame('chasseur', $editable['title'] ?? null);
        self::assertSame(['chasseur'], $editable['ownedTitles'] ?? null);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mem');
        $profile = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($profile);
        self::assertSame(['label' => 'Chasseur de goals', 'rarity' => 'common', 'icon' => null, 'access' => 'shop', 'origin' => 'Acheté en boutique'], $profile['title'] ?? null);

        // Saving without the field keeps it; an empty title takes it off.
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bio' => 'Salut']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mem');
        $kept = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($kept);
        self::assertIsArray($kept['title'] ?? null);
        self::assertSame('Chasseur de goals', $kept['title']['label'] ?? null);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => '']);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mem');
        $off = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($off);
        self::assertArrayHasKey('title', $off);
        self::assertNull($off['title']);
    }

    public function testATitleShinesByItsRarityWithItsIconOnTheProfileAndTheCards(): void
    {
        // Story 41.27: the admin picks a rarity and an icon; the badge goes with the title everywhere.
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $this->client->jsonRequest('POST', '/api/v1/admin/profile-titles', ['key' => 'legende', 'label' => 'Légende', 'access' => 'free', 'rarity' => 'legendary', 'icon' => 'crown']);
        self::assertResponseStatusCodeSame(201);
        foreach ([['rarity' => 'mythic'], ['icon' => 'unicorn']] as $payload) {
            $this->client->jsonRequest('PATCH', '/api/v1/admin/profile-titles/legende', $payload);
            self::assertResponseStatusCodeSame(422, (string) json_encode($payload));
        }
        $this->client->jsonRequest('PATCH', '/api/v1/admin/profile-titles/legende', ['rarity' => 'epic']);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/profile-titles');
        self::assertSame([['key' => 'legende', 'label' => 'Légende', 'access' => 'free', 'rarity' => 'epic', 'icon' => 'crown']], $this->decodedJsonResponse()['titles'] ?? null, 'the icon kept when only the rarity changes');

        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member', slug: 'mem');
        $this->loginAs($member);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => 'legende', 'audience' => 'public']);
        self::assertResponseIsSuccessful();
        $badge = ['label' => 'Légende', 'rarity' => 'epic', 'icon' => 'crown', 'access' => 'free'];

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mem');
        $profile = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($profile);
        self::assertSame([...$badge, 'origin' => null], $profile['title'] ?? null, 'free for all: no origin of its own');

        // A card: the comment author.
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/mem/comments', ['body' => 'Salut']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mem/comments');
        $comments = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($comments);
        self::assertIsArray($comments[0] ?? null);
        self::assertIsArray($comments[0]['author'] ?? null);
        self::assertSame($badge, $comments[0]['author']['title'] ?? null);

        // No icon any more: an empty one takes it off.
        $this->loginAs($this->createUser('admin2@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $this->client->jsonRequest('PATCH', '/api/v1/admin/profile-titles/legende', ['icon' => '']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/profile-titles');
        $titles = $this->decodedJsonResponse()['titles'] ?? null;
        self::assertIsArray($titles);
        self::assertIsArray($titles[0] ?? null);
        self::assertArrayHasKey('icon', $titles[0]);
        self::assertNull($titles[0]['icon']);
    }

    private function write(string $key, string $label, string $access): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/profile-titles', ['key' => $key, 'label' => $label, 'access' => $access]);
        self::assertResponseStatusCodeSame(201);
    }

    private function gold(User $member, int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }
}
