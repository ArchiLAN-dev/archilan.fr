<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\BadGatewayException;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ApplicationExceptionTest extends TestCase
{
    public function testNotFoundMapsTo404WithDefaultCode(): void
    {
        $e = new NotFoundException('Article introuvable.');

        self::assertInstanceOf(ApplicationFailure::class, $e);
        self::assertSame(404, $e->statusCode());
        self::assertSame('not_found', $e->errorCode());
        self::assertSame('Article introuvable.', $e->clientMessage());
        self::assertSame([], $e->details());
    }

    public function testErrorCodeIsOverridable(): void
    {
        $e = new ServiceUnavailableException('Le stockage est indisponible.', 'storage_unavailable');

        self::assertSame(503, $e->statusCode());
        self::assertSame('storage_unavailable', $e->errorCode());
    }

    public function testConflictMapsTo409(): void
    {
        self::assertSame(409, new ConflictException('Doublon.')->statusCode());
        self::assertSame('conflict', new ConflictException('Doublon.')->errorCode());
    }

    public function testForbiddenMapsTo403(): void
    {
        $e = new ForbiddenException('Accès refusé.');

        self::assertInstanceOf(ApplicationFailure::class, $e);
        self::assertSame(403, $e->statusCode());
        self::assertSame('forbidden', $e->errorCode());
    }

    public function testBadGatewayMapsTo502(): void
    {
        $e = new BadGatewayException("L'envoi a échoué.", 'message_send_failed');

        self::assertInstanceOf(ApplicationFailure::class, $e);
        self::assertSame(502, $e->statusCode());
        self::assertSame('message_send_failed', $e->errorCode());
    }

    public function testValidationMapsTo422AndCarriesTheFieldMap(): void
    {
        $details = ['email' => ['Adresse invalide.']];
        $e = new ValidationException('Validation échouée.', $details);

        self::assertSame(422, $e->statusCode());
        self::assertSame('validation_failed', $e->errorCode());
        self::assertSame($details, $e->details());
    }

    public function testIsThrowable(): void
    {
        $this->expectException(NotFoundException::class);

        throw new NotFoundException('Introuvable.');
    }
}
