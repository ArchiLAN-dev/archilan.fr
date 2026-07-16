<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Infrastructure\Http\ApplicationFailureListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApplicationFailureListenerTest extends TestCase
{
    public function testMapsAnApplicationFailureToTheErrorEnvelope(): void
    {
        $event = $this->exceptionEvent(new NotFoundException('Article introuvable.'));

        (new ApplicationFailureListener())($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['error' => ['code' => 'not_found', 'message' => 'Article introuvable.']],
            $this->decode($response->getContent()),
        );
    }

    public function testIncludesDetailsWhenPresent(): void
    {
        $event = $this->exceptionEvent(
            new ValidationException('Validation échouée.', ['email' => ['Adresse invalide.']]),
        );

        (new ApplicationFailureListener())($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['error' => [
                'code' => 'validation_failed',
                'message' => 'Validation échouée.',
                'details' => ['email' => ['Adresse invalide.']],
            ]],
            $this->decode($response->getContent()),
        );
    }

    public function testIgnoresNonApplicationFailures(): void
    {
        $event = $this->exceptionEvent(new \RuntimeException('boom'));

        (new ApplicationFailureListener())($event);

        self::assertNull($event->getResponse());
    }

    private function exceptionEvent(\Throwable $throwable): ExceptionEvent
    {
        $kernel = self::createStub(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string|false $json): array
    {
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
