<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Story 41.15: the admins write the weekly quests, set how many a week, and plan the current and coming weeks.
 */
final class AdminQuestTest extends FunctionalTestCase
{
    public function testAnAdminWritesAQuestAndTheCurrentWeekDrawsIt(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        $id = $this->write(['title' => 'Marathon', 'description' => 'Joue beaucoup.', 'reward' => 60, 'inDraw' => true, 'objectives' => [
            ['metric' => 'checks', 'target' => 50],
            ['metric' => 'sessions', 'target' => 2],
        ]]);

        $overview = $this->overview();
        self::assertSame(3, $overview['questsPerWeek'] ?? null);
        $quests = $overview['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertIsArray($quests[0] ?? null);
        self::assertSame([['metric' => 'checks', 'target' => 50], ['metric' => 'sessions', 'target' => 2]], $quests[0]['objectives'] ?? null);

        $weeks = $this->weeks($overview);
        self::assertCount(9, $weeks, 'the current week and 8 coming ones');
        self::assertTrue($weeks[0]['current'] ?? null);
        self::assertTrue($weeks[0]['drawn'] ?? null);
        self::assertSame([['questId' => $id, 'title' => 'Marathon', 'reward' => 60, 'origin' => 'drawn', 'retired' => false]], $weeks[0]['quests'] ?? null);
        self::assertFalse($weeks[1]['drawn'] ?? null, 'a coming week is drawn when it starts');
    }

    public function testAQuestMustBePayableAndCountable(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        foreach ([
            ['title' => '', 'reward' => 10, 'objectives' => [['metric' => 'goals', 'target' => 1]]],
            ['title' => 'Trop', 'reward' => 5000, 'objectives' => [['metric' => 'goals', 'target' => 1]]],
            ['title' => 'Vide', 'reward' => 10, 'objectives' => []],
            ['title' => 'Inconnu', 'reward' => 10, 'objectives' => [['metric' => 'kudos', 'target' => 1]]],
            ['title' => 'Double', 'reward' => 10, 'objectives' => [['metric' => 'goals', 'target' => 1], ['metric' => 'goals', 'target' => 2]]],
        ] as $payload) {
            $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, (string) json_encode($payload));
        }

        $this->client->request('PUT', '/api/v1/admin/quests-settings', [], [], ['CONTENT_TYPE' => 'application/json'], '{"questsPerWeek": 11}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/api/v1/admin/quests-settings', [], [], ['CONTENT_TYPE' => 'application/json'], '{"questsPerWeek": 5}');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(5, $this->overview()['questsPerWeek'] ?? null);
    }

    public function testAQuestOutOfTheDrawIsPinnedToAComingWeekAndRetiringItUnpinsIt(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $special = $this->write(['title' => 'Spéciale LAN', 'reward' => 100, 'inDraw' => false, 'objectives' => [['metric' => 'goals', 'target' => 1]]]);
        $other = $this->write(['title' => 'Autre', 'reward' => 20, 'inDraw' => false, 'objectives' => [['metric' => 'checks', 'target' => 10]]]);
        $next = QuestWeek::containing(new \DateTimeImmutable())->next()->key;

        $this->pin($next, ['questId' => $special]);
        self::assertResponseStatusCodeSame(204);
        $this->pin($next, ['questId' => $special]);
        self::assertResponseStatusCodeSame(409, 'already in the week');

        $weeks = $this->weeks($this->overview());
        self::assertSame([], $weeks[0]['quests'] ?? null, 'out of the draw: the current week does not draw it');
        self::assertSame('pinned', $this->served($weeks[1])[0]['origin'] ?? null);

        // Replaced by another quest, at the same place.
        $this->pin($next, ['questId' => $other, 'replaces' => $special]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$other], array_column($this->served($this->weeks($this->overview())[1]), 'questId'));

        // Retired: its pins on the weeks not drawn yet go.
        $this->client->request('POST', sprintf('/api/v1/admin/quests/%s/retire', $other));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->weeks($this->overview())[1]['quests'] ?? null);
        $this->pin($next, ['questId' => $other]);
        self::assertResponseStatusCodeSame(409, 'a retired quest no longer pins');

        $this->pin(QuestWeek::containing(new \DateTimeImmutable())->previous()->key, ['questId' => $special]);
        self::assertResponseStatusCodeSame(422, 'a past week is history');
        $this->client->request('DELETE', sprintf('/api/v1/admin/quest-weeks/%s/quests/%s', $next, $special));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCurrentWeekCanBeChangedByHand(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $drawn = $this->write(['title' => 'Tirée', 'reward' => 30, 'inDraw' => true, 'objectives' => [['metric' => 'goals', 'target' => 1]]]);
        $imposed = $this->write(['title' => 'Imposée', 'reward' => 30, 'inDraw' => false, 'objectives' => [['metric' => 'weeklies', 'target' => 1]]]);
        $current = QuestWeek::containing(new \DateTimeImmutable())->key;
        self::assertSame([$drawn], array_column($this->served($this->weeks($this->overview())[0]), 'questId'));

        $this->pin($current, ['questId' => $imposed, 'replaces' => $drawn]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$imposed], array_column($this->served($this->weeks($this->overview())[0]), 'questId'), 'not drawn again');

        $this->client->request('DELETE', sprintf('/api/v1/admin/quest-weeks/%s/quests/%s', $current, $imposed));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->weeks($this->overview())[0]['quests'] ?? null);
    }

    public function testOnlyAnAdminManagesTheQuests(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER'], 'Member'));

        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(array $payload): string
    {
        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->decodedJsonResponse()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pin(string $weekKey, array $payload): void
    {
        $this->client->request('POST', sprintf('/api/v1/admin/quest-weeks/%s/quests', $weekKey), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> */
    private function overview(): array
    {
        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseIsSuccessful();

        return $this->decodedJsonResponse();
    }

    /**
     * @param array<mixed> $overview
     *
     * @return list<array<mixed>>
     */
    private function weeks(array $overview): array
    {
        $weeks = $overview['weeks'] ?? null;
        self::assertIsArray($weeks);

        return array_values(array_filter($weeks, is_array(...)));
    }

    /**
     * @param array<mixed> $week
     *
     * @return list<array<mixed>>
     */
    private function served(array $week): array
    {
        $quests = $week['quests'] ?? null;
        self::assertIsArray($quests);

        return array_values(array_filter($quests, is_array(...)));
    }
}
