<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Handler\ResumeRunJobHandler;
use App\Sessions\Application\Message\ResumeRunJob;
use App\Sessions\Application\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ResumeRunJobHandlerTest extends TestCase
{
    public function testInvokeRelaunchesFromSaveViaOrchestrateur(): void
    {
        $gateway = $this->createMock(RunnerGatewayInterface::class);
        $gateway->expects(self::once())->method('relaunchFromSave')->with('sess-1');

        $handler = new ResumeRunJobHandler($gateway, new NullLogger());
        $handler(new ResumeRunJob('sess-1', 'orchestrateur:volume', 'pw', 'admin-pw', 25000));
    }
}
