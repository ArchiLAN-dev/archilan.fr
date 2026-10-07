<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Fixtures\Wallet\SpyQuestAnnouncementChannel;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Story 41.26: an admin announces a week's quests on Discord by hand; the week keeps one message, updated by every
 * new announcement.
 */
final class QuestDiscordAnnouncementTest extends FunctionalTestCase
{
    private const string WEEK = '2026-W41';

    public function testTheAdminPostsTheWeekThenUpdatesItsMessage(): void
    {
        $channel = $this->channel();
        $this->quest();
        $this->asAdmin();

        $this->client->request('POST', '/api/v1/admin/quest-weeks/'.self::WEEK.'/announce-discord');
        self::assertResponseIsSuccessful();
        self::assertSame(['outcome' => 'posted'], $this->decodedJsonResponse());
        self::assertSame('m1', $this->settings()->discordMessageOf(self::WEEK));

        $this->client->request('POST', '/api/v1/admin/quest-weeks/'.self::WEEK.'/announce-discord');
        self::assertResponseIsSuccessful();
        self::assertSame(['outcome' => 'updated'], $this->decodedJsonResponse());
        self::assertSame([null, 'm1'], $channel->messageIds, 'the second announcement edits the first message');
        self::assertNull($this->settings()->discordMessageOf('2026-W42'), 'another week has a message of its own');

        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decodedJsonResponse()['discordEnabled'] ?? null);
    }

    public function testTheAnnouncementIsRefusedWithAReason(): void
    {
        $channel = $this->channel();
        $this->asAdmin();

        $this->client->request('POST', '/api/v1/admin/quest-weeks/'.self::WEEK.'/announce-discord');
        self::assertResponseStatusCodeSame(409, 'no quest to announce');
        $this->client->request('POST', '/api/v1/admin/quest-weeks/not-a-week/announce-discord');
        self::assertResponseStatusCodeSame(404);

        $this->quest();
        $channel->failing = true;
        $this->client->request('POST', '/api/v1/admin/quest-weeks/'.self::WEEK.'/announce-discord');
        self::assertResponseStatusCodeSame(502, 'Discord refused');

        $channel->enabled = false;
        $this->client->request('POST', '/api/v1/admin/quest-weeks/'.self::WEEK.'/announce-discord');
        self::assertResponseStatusCodeSame(409, 'no Discord configured');
        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertFalse($this->decodedJsonResponse()['discordEnabled'] ?? null);

        $this->loginAs($this->createUser('member@example.org'));
        $this->client->request('POST', '/api/v1/admin/quest-weeks/'.self::WEEK.'/announce-discord');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheConsoleCommandAnnouncesTheGivenWeek(): void
    {
        $this->channel();
        $this->quest();
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('app:quests:announce-discord'));

        self::assertSame(0, $tester->execute(['week' => self::WEEK]));
        self::assertStringContainsString('Quests of 2026-W41 posted on Discord.', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['week' => self::WEEK]));
        self::assertStringContainsString('Discord message of 2026-W41 updated.', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['week' => '2026-W42']), 'a week with no quest fails');
    }

    private function channel(): SpyQuestAnnouncementChannel
    {
        $channel = new SpyQuestAnnouncementChannel();
        $this->client->disableReboot();
        self::getContainer()->set(QuestAnnouncementChannelInterface::class, $channel);

        return $channel;
    }

    private function quest(): void
    {
        $week = QuestWeek::fromKey(self::WEEK);
        self::assertNotNull($week);
        $this->entityManager->persist(QuestDefinition::write('Marathon', '', 60, [new QuestObjective(QuestMetric::Checks, 50)], true, 1, $week->start->modify('-1 month'), 'marathon'));
        $this->entityManager->flush();
    }

    private function asAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
    }

    private function settings(): QuestRepositoryInterface
    {
        $settings = self::getContainer()->get(QuestRepositoryInterface::class);
        self::assertInstanceOf(QuestRepositoryInterface::class, $settings);

        return $settings;
    }
}
