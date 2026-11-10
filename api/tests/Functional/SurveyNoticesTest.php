<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\Message\SurveyNoticeMessage;
use App\Identity\Domain\Entity\User;
use App\Surveys\Application\Handler\NotifySurveysHandler;
use App\Surveys\Application\Message\NotifySurveysMessage;
use App\Surveys\Application\Port\SurveyEventInterface;
use App\Surveys\Application\Query\SurveyAudienceQueryInterface;
use App\Surveys\Application\Service\SurveyAnnouncer;
use App\Surveys\Domain\Repository\SurveyRepositoryInterface;
use App\Tests\Unit\GameSelection\SpyNotifier;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Story 51.3: a survey's audience is told it opened, then reminded once; an admin may remind by hand. */
final class SurveyNoticesTest extends FunctionalTestCase
{
    private const string OPEN = '2026-01-01T00:00:00+00:00';
    private const string CLOSE = '2030-01-01T00:00:00+00:00';

    private User $admin;
    private string $eventId;
    private SpyNotifier $notifier;

    /** @var list<object> */
    private array $mails = [];

    /** @var array<string, User> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new SpyNotifier();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $this->eventId = $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable('2027-06-05T09:00:00+00:00'),
            new \DateTimeImmutable('2027-06-06T18:00:00+00:00'),
        )->getId();
        foreach (['alice', 'bob', 'chloe', 'dave'] as $name) {
            $this->members[$name] = $this->createUser($name.'@example.org', ['ROLE_USER'], ucfirst($name));
            $this->createRegistration($this->eventId, $this->members[$name]->getId());
        }
    }

    public function testTheAudienceIsToldOnceThenRemindedOnceSkippingWhoAnsweredOrLeft(): void
    {
        $survey = $this->publishedSurvey(anonymous: true);
        $this->answerAs('bob', $survey);
        // Chloé deleted her account, Dave has no e-mail address left.
        $this->connection()->update($this->connection()->quoteSingleIdentifier('user'), ['deleted_at' => '2026-06-01 00:00:00+00'], ['id' => $this->members['chloe']->getId()]);
        $this->connection()->update($this->connection()->quoteSingleIdentifier('user'), ['email' => ''], ['id' => $this->members['dave']->getId()]);

        $this->runPassAt('2027-01-01T00:00:00+00:00');
        $this->runPassAt('2027-01-01T00:10:00+00:00');

        self::assertSame([
            [$this->members['alice']->getId(), 'survey_opened'],
            [$this->members['dave']->getId(), 'survey_opened'],
        ], $this->notified());
        self::assertSame('Viens-tu ?', $this->notifier->sent[0]['payload']['surveyTitle'] ?? null);
        self::assertCount(1, $this->mails);
        $mail = $this->mails[0];
        self::assertInstanceOf(SurveyNoticeMessage::class, $mail);
        self::assertSame('alice@example.org', $mail->userEmail);
        self::assertFalse($mail->reminder);
        self::assertTrue($mail->anonymous);
        self::assertSame('ArchiLAN 4', $mail->eventTitle);
        self::assertSame('01/01/2030 à 01h00', $mail->closesAt);
        self::assertSame(sprintf('https://archilan.test/evenements/%s/sondages/%s', $this->eventId, $survey['id']), $mail->answerUrl);

        $this->notifier->sent = [];
        $this->mails = [];
        $this->runPassAt('2029-12-29T23:00:00+00:00');
        self::assertSame([], $this->notified());

        $this->runPassAt('2029-12-30T00:10:00+00:00');
        $this->runPassAt('2029-12-30T00:20:00+00:00');
        self::assertSame([
            [$this->members['alice']->getId(), 'survey_reminder'],
            [$this->members['dave']->getId(), 'survey_reminder'],
        ], $this->notified());
        self::assertCount(1, $this->mails);
        self::assertInstanceOf(SurveyNoticeMessage::class, $this->mails[0]);
        self::assertTrue($this->mails[0]->reminder);
    }

    public function testWithoutEmailOnlyTheBellRings(): void
    {
        $this->publishedSurvey(sendEmail: false);

        $this->runPassAt('2027-01-01T00:00:00+00:00');

        self::assertCount(4, $this->notified());
        self::assertSame([], $this->mails);
    }

    public function testADraftWaitsAndASurveyPublishedLateIsAnnouncedAtTheNextPass(): void
    {
        $survey = $this->draftSurvey();
        $this->runPassAt('2027-01-01T00:00:00+00:00');
        self::assertSame([], $this->notified());

        $this->loginAs($this->admin);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/publish');
        self::assertResponseStatusCodeSame(204);
        $this->runPassAt('2027-01-01T00:10:00+00:00');

        self::assertCount(4, $this->notified());
    }

    public function testAnAdminRemindsTheNonRespondentsAtMostOnceADay(): void
    {
        $survey = $this->publishedSurvey();
        $this->answerAs('bob', $survey);

        $this->loginAs($this->admin);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/remind');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(3, $data['recipients'] ?? null);
        self::assertIsString($data['lastRemindedAt'] ?? null);
        self::assertSame(3, $this->notificationRows('survey_reminder'));

        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/remind');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('survey_remind_too_soon', $this->errorCode());
        self::assertSame(3, $this->notificationRows('survey_reminder'));

        // The opening notice is not sent on top of a manual reminder.
        $this->client->request('GET', '/api/v1/admin/surveys/'.$survey['id']);
        $view = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($view);
        self::assertIsString($view['openingNotifiedAt'] ?? null);
        self::assertSame($data['lastRemindedAt'], $view['lastRemindedAt'] ?? null);
    }

    public function testAClosedSurveyIsNotRemindedAndTheRouteIsForAdmins(): void
    {
        $survey = $this->publishedSurvey(closesAt: '2026-02-01T00:00:00+00:00');

        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/remind');
        self::assertResponseStatusCodeSame(401);
        $this->loginAs($this->members['alice']);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/remind');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->admin);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/remind');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('survey_closed', $this->errorCode());
    }

    /** @return array{id: string, question: string} */
    private function publishedSurvey(bool $anonymous = false, bool $sendEmail = true, string $closesAt = self::CLOSE): array
    {
        $survey = $this->draftSurvey($anonymous, $sendEmail, $closesAt);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/publish');
        self::assertResponseStatusCodeSame(204);
        $this->client->getCookieJar()->clear();

        return $survey;
    }

    /** @return array{id: string, question: string} */
    private function draftSurvey(bool $anonymous = false, bool $sendEmail = true, string $closesAt = self::CLOSE): array
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$this->eventId.'/surveys', [
            'title' => 'Viens-tu ?',
            'anonymous' => $anonymous,
            'sendEmail' => $sendEmail,
            'opensAt' => self::OPEN,
            'closesAt' => $closesAt,
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->createdId();
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$id.'/questions', ['kind' => 'text', 'label' => 'Un mot ?', 'required' => true]);
        self::assertResponseStatusCodeSame(201);

        return ['id' => $id, 'question' => $this->createdId()];
    }

    /** @param array{id: string, question: string} $survey */
    private function answerAs(string $name, array $survey): void
    {
        $this->loginAs($this->members[$name]);
        $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$survey['id'].'/my-response', ['answers' => [['questionId' => $survey['question'], 'text' => 'Oui']]]);
        self::assertResponseStatusCodeSame(204);
        $this->client->getCookieJar()->clear();
    }

    private function runPassAt(string $now): void
    {
        $container = self::getContainer();
        $surveys = $container->get(SurveyRepositoryInterface::class);
        $audience = $container->get(SurveyAudienceQueryInterface::class);
        $events = $container->get(SurveyEventInterface::class);
        self::assertInstanceOf(SurveyRepositoryInterface::class, $surveys);
        self::assertInstanceOf(SurveyAudienceQueryInterface::class, $audience);
        self::assertInstanceOf(SurveyEventInterface::class, $events);

        $bus = new readonly class($this) implements MessageBusInterface {
            public function __construct(private SurveyNoticesTest $test)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->test->record($message);

                return new Envelope($message, $stamps);
            }
        };

        $announcer = new SurveyAnnouncer($audience, $events, $this->notifier, $bus, new NullLogger(), 'https://archilan.test');
        $handler = new NotifySurveysHandler($surveys, $announcer, new MockClock(new \DateTimeImmutable($now)));
        $handler(new NotifySurveysMessage());
        $this->entityManager->clear();
    }

    public function record(object $message): void
    {
        $this->mails[] = $message;
    }

    /** @return list<array{0: string, 1: string}> */
    private function notified(): array
    {
        $sent = array_map(static fn (array $notice): array => [$notice['recipientId'], $notice['type']], $this->notifier->sent);
        usort($sent, fn (array $a, array $b): int => $this->nameOf($a[0]) <=> $this->nameOf($b[0]));

        return $sent;
    }

    private function nameOf(string $userId): string
    {
        foreach ($this->members as $name => $user) {
            if ($user->getId() === $userId) {
                return $name;
            }
        }

        return $userId;
    }

    private function notificationRows(string $type): int
    {
        $count = $this->connection()->createQueryBuilder()->select('COUNT(*)')->from('community_notification')->where('type = :type')->setParameter('type', $type)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    private function createdId(): string
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    private function errorCode(): string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertIsString($error['code'] ?? null);

        return $error['code'];
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
