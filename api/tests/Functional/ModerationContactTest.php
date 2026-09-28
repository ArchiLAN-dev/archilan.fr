<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\ModerationAction;
use App\Identity\Application\Support\ModerationContactPass;
use App\Identity\Domain\Entity\User;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Story 39.2: a sanctioned member writes to the moderation - from their account when only warned, and with
 * the contact pass the login hands out when they are banned or suspended.
 */
final class ModerationContactTest extends FunctionalTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testABannedMemberGetsAPassAndWritesWithIt(): void
    {
        $member = $this->bannedMember();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['email' => 'bad@example.org', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403);
        $pass = $this->passCookie();
        self::assertNotNull($pass, 'the pass comes with the refusal');
        self::assertTrue($pass->isHttpOnly());
        self::assertTrue($pass->isSecure());
        self::assertSame(ModerationContactPass::COOKIE_PATH, $pass->getPath(), 'good for the contact route only');
        self::assertNull($this->responseCookie('__Host-archilan_session'), 'still no session');

        $this->usePass((string) $pass->getValue());
        $this->client->jsonRequest('GET', ModerationContactPass::COOKIE_PATH);
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame('banned', $data['status'] ?? null);
        self::assertSame('Récidive', $data['reason'] ?? null);

        $this->client->jsonRequest('POST', ModerationContactPass::COOKIE_PATH, ['body' => "OK pour le ban, mais j'aimerais être remboursé"]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', ModerationContactPass::COOKIE_PATH);
        $messages = $this->data()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertIsArray($messages[0]);
        self::assertSame("OK pour le ban, mais j'aimerais être remboursé", $messages[0]['body']);

        // The staff reads it in the member's case.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/community/accounts/%s/moderation', $member->getId()));
        $case = $this->data()['case'] ?? null;
        self::assertIsArray($case);
        self::assertIsArray($case['messages']);
        self::assertCount(1, $case['messages']);
        self::assertIsArray($case['messages'][0]);
        self::assertSame('member', $case['messages'][0]['author']);
        self::assertSame('Bad', $case['messages'][0]['authorName']);
    }

    public function testASuspendedMemberGetsAPassToo(): void
    {
        // Story 39.9: the pass was only tested for a ban.
        $this->registerUser('late@example.org', displayName: 'Late');
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'late@example.org']);
        self::assertInstanceOf(User::class, $user);
        $user->suspendUntil(new \DateTimeImmutable('+10 days'), 'Comportement', new \DateTimeImmutable());
        $this->entityManager->persist(ModerationAction::create('admin-id', $user->getId(), ModerationAction::ACTION_SUSPEND, 'Comportement', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['email' => 'late@example.org', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403);
        $pass = $this->passCookie();
        self::assertNotNull($pass);

        $this->usePass((string) $pass->getValue());
        $this->client->jsonRequest('GET', ModerationContactPass::COOKIE_PATH);
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame('suspended', $data['status'] ?? null);
        self::assertNotNull($data['suspendedUntil'] ?? null);
    }

    public function testAWrongPasswordGetsNoPass(): void
    {
        $this->bannedMember();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['email' => 'bad@example.org', 'password' => 'wrong']);

        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->passCookie());
    }

    public function testNoPassNoContact(): void
    {
        $this->client->jsonRequest('POST', ModerationContactPass::COOKIE_PATH, ['body' => 'Bonjour']);
        self::assertResponseStatusCodeSame(401);

        // A session is not a pass.
        $user = $this->createUser('someone@example.org');
        $this->loginAs($user);
        $this->client->getCookieJar()->set(new Cookie(ModerationContactPass::COOKIE_NAME, (string) $this->client->getCookieJar()->get('__Host-archilan_session')?->getValue(), null, ModerationContactPass::COOKIE_PATH));
        $this->client->jsonRequest('GET', ModerationContactPass::COOKIE_PATH);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAPassIsWorthNothingOnceTheSanctionIsLifted(): void
    {
        $member = $this->bannedMember();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['email' => 'bad@example.org', 'password' => self::PASSWORD]);
        $value = (string) $this->passCookie()?->getValue();

        $member->lift(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->usePass($value);
        $this->client->jsonRequest('GET', ModerationContactPass::COOKIE_PATH);
        self::assertResponseStatusCodeSame(401, 'a member back in logs in and writes from their account');
    }

    public function testAWarnedMemberWritesFromTheirAccount(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('warned@example.org', displayName: 'Warned', slug: 'warned');
        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/warn', $member->getId()), ['reason' => 'Spam']);
        self::assertResponseStatusCodeSame(204);

        $this->client->getCookieJar()->clear();
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/account/moderation-contact');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->data()['available'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/account/moderation-contact', ['body' => 'Désolé, ça ne se reproduira pas']);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/account/moderation-contact');
        $messages = $this->data()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
    }

    public function testAMemberNeverSanctionedHasNothingToContest(): void
    {
        $member = $this->createUser('fine@example.org');
        $this->loginAs($member);

        $this->client->jsonRequest('GET', '/api/v1/account/moderation-contact');
        self::assertFalse($this->data()['available'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/account/moderation-contact', ['body' => 'Bonjour']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $this->bannedMember();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['email' => 'bad@example.org', 'password' => self::PASSWORD]);
        $this->usePass((string) $this->passCookie()?->getValue());

        $this->client->jsonRequest('POST', ModerationContactPass::COOKIE_PATH, ['body' => '   ']);

        self::assertResponseStatusCodeSame(422);
    }

    private function bannedMember(): User
    {
        $this->registerUser('bad@example.org', displayName: 'Bad');
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'bad@example.org']);
        self::assertInstanceOf(User::class, $user);
        $user->ban('Récidive', new \DateTimeImmutable());
        $this->entityManager->persist(ModerationAction::create('admin-id', $user->getId(), ModerationAction::ACTION_BAN, 'Récidive', new \DateTimeImmutable()));
        $this->entityManager->flush();

        return $user;
    }

    private function passCookie(): ?\Symfony\Component\HttpFoundation\Cookie
    {
        return $this->responseCookie(ModerationContactPass::COOKIE_NAME);
    }

    private function responseCookie(string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    private function usePass(string $value): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie(ModerationContactPass::COOKIE_NAME, $value, null, ModerationContactPass::COOKIE_PATH));
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
