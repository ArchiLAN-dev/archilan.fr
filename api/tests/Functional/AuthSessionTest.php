<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Application\RegisterLambdaUser;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\User;
use App\Identity\Presentation\AuthController;
use Doctrine\ORM\Tools\SchemaTool;

final class AuthSessionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(RefreshToken::class),
            $this->entityManager->getClassMetadata(EmailConfirmationToken::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema(array_reverse($metadata));
        $schemaTool->createSchema($metadata);
    }

    public function testLoginSetsTwoCookiesWithoutReturningToken(): void
    {
        $this->createLambdaUser();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertArrayNotHasKey('token', $response);
        self::assertArrayNotHasKey('jwt', $response);

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(2, $cookies);

        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }

        self::assertArrayHasKey(AuthSessionSigner::COOKIE_NAME, $cookiesByName);
        self::assertArrayHasKey(AuthController::REFRESH_COOKIE_NAME, $cookiesByName);

        $accessCookie = $cookiesByName[AuthSessionSigner::COOKIE_NAME];
        self::assertTrue($accessCookie->isSecure());
        self::assertTrue($accessCookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $accessCookie->getSameSite()));
        self::assertSame('/', $accessCookie->getPath());

        $refreshCookie = $cookiesByName[AuthController::REFRESH_COOKIE_NAME];
        self::assertTrue($refreshCookie->isSecure());
        self::assertTrue($refreshCookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $refreshCookie->getSameSite()));
        self::assertSame(AuthController::REFRESH_COOKIE_SCOPE, $refreshCookie->getPath());
    }

    public function testAccessTokenCookieHas15MinuteTTL(): void
    {
        $this->createLambdaUser();

        $before = time();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);
        $after = time();

        self::assertResponseIsSuccessful();

        $cookies = $this->client->getResponse()->headers->getCookies();
        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }

        $accessCookie = $cookiesByName[AuthSessionSigner::COOKIE_NAME] ?? null;
        self::assertNotNull($accessCookie);

        $maxAge = $accessCookie->getMaxAge();
        self::assertGreaterThanOrEqual(840, $maxAge);
        self::assertLessThanOrEqual(960, $maxAge);
    }

    public function testRememberMeTrueGivesThirtyDayCookieTtl(): void
    {
        $this->createLambdaUser();

        $before = time();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
            'rememberMe' => true,
        ]);
        $after = time();

        self::assertResponseIsSuccessful();

        $cookies = $this->client->getResponse()->headers->getCookies();
        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }

        $refresh = $cookiesByName[AuthController::REFRESH_COOKIE_NAME] ?? null;
        self::assertNotNull($refresh);

        $expectedMin = $before + 30 * 86400 - 5;
        $expectedMax = $after + 30 * 86400 + 5;
        self::assertGreaterThanOrEqual($expectedMin, $refresh->getExpiresTime());
        self::assertLessThanOrEqual($expectedMax, $refresh->getExpiresTime());
    }

    public function testRememberMeFalseGivesOneDayCookieTtl(): void
    {
        $this->createLambdaUser();

        $before = time();
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
            'rememberMe' => false,
        ]);
        $after = time();

        self::assertResponseIsSuccessful();

        $cookies = $this->client->getResponse()->headers->getCookies();
        $cookiesByName = [];
        foreach ($cookies as $c) {
            $cookiesByName[$c->getName()] = $c;
        }

        $refresh = $cookiesByName[AuthController::REFRESH_COOKIE_NAME] ?? null;
        self::assertNotNull($refresh);

        $expectedMin = $before + 1 * 86400 - 5;
        $expectedMax = $after + 1 * 86400 + 5;
        self::assertGreaterThanOrEqual($expectedMin, $refresh->getExpiresTime());
        self::assertLessThanOrEqual($expectedMax, $refresh->getExpiresTime());
    }

    public function testInvalidCredentialsReturnGenericAuthenticationError(): void
    {
        $this->createLambdaUser();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'wrong password',
        ]);

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('invalid_credentials', $response['error']['code']);
        self::assertSame('Email ou mot de passe incorrect.', $response['error']['message']);
    }

    public function testCurrentSessionReadsHttpOnlyCookieServerSide(): void
    {
        $this->createLambdaUser();
        $this->login();

        $this->client->jsonRequest('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('jean@example.org', $response['data']['email']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);
    }

    public function testMeWithoutCookieReturnsUnauthenticated(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testMeWithTamperedCookieReturnsUnauthenticated(): void
    {
        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(AuthSessionSigner::COOKIE_NAME, 'tampered.invalidsignature'),
        );

        $this->client->jsonRequest('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testLogoutClearsSessionCookie(): void
    {
        $this->createLambdaUser();
        $this->login();

        $this->client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseIsSuccessful();
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertSame(AuthSessionSigner::COOKIE_NAME, $cookie->getName());
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }

    private function createLambdaUser(): void
    {
        $registerLambdaUser = self::getContainer()->get(RegisterLambdaUser::class);
        self::assertInstanceOf(RegisterLambdaUser::class, $registerLambdaUser);
        $result = $registerLambdaUser->register('jean@example.org', 'correct horse battery staple', true);
        self::assertSame([], $result['errors']);
    }

    private function login(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();
    }
}
