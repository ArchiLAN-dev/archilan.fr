<?php

declare(strict_types=1);

namespace App\Command;

use Archilan\BridgeClient\Ws\Exception\WsAuthException;
use Archilan\BridgeClient\Ws\Exception\WsConnectionException;
use Archilan\BridgeClient\Ws\Listener\BridgeEventListener;
use Archilan\BridgeClient\Ws\Listener\HeartbeatListener;
use Archilan\BridgeClient\Ws\Listener\RestartApprovalHandler;
use Archilan\BridgeClient\Ws\Message\ApproveRestartRequest;
use Archilan\BridgeClient\Ws\Message\BridgeEvent;
use Archilan\BridgeClient\Ws\WsEventDispatcher;
use Archilan\BridgeClientBundle\Bridge\BridgeClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Permanent live listener — reconnects automatically on connection loss.
 *
 * Uses BridgeClientFactory from the bundle (validates DI wiring) but creates
 * its own inline subscribers so that test subscribers (TestFeedSubscriber etc.)
 * don't interfere (e.g. auto-stopping after 2 feed events).
 *
 *   php bin/console test:listen http://localhost:25001
 *   php bin/console test:listen http://localhost:25001 --retry-delay=10
 *
 * Stop with Ctrl+C (SIGINT) or SIGTERM.
 * Graceful shutdown requires the pcntl extension (standard on Linux / Docker).
 * Without pcntl the process terminates abruptly on Ctrl+C — that is fine for dev use.
 */
#[AsCommand(
    name: 'test:listen',
    description: 'Permanent live listener for the bridge WebSocket (reconnects automatically).',
)]
final class ListenCommand extends Command
{
    private bool $running = true;

    private ?WsEventDispatcher $dispatcher = null;

    public function __construct(private readonly BridgeClientFactory $factory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'bridge-url',
                InputArgument::REQUIRED,
                'HTTP base URL of the bridge (e.g. http://localhost:25001).',
            )
            ->addOption(
                'retry-delay',
                null,
                InputOption::VALUE_REQUIRED,
                'Initial seconds between reconnection attempts (doubles on each failure, max 60).',
                '5',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $url = $input->getArgument('bridge-url');

        if (!is_string($url) || '' === $url) {
            $io->error('bridge-url argument is required.');

            return Command::INVALID;
        }

        $retryOption  = $input->getOption('retry-delay');
        $baseDelay    = is_numeric($retryOption) ? max(1, (int) $retryOption) : 5;
        $currentDelay = $baseDelay;

        // Graceful shutdown via SIGINT / SIGTERM when pcntl is available (Linux / Docker).
        // Without pcntl (Windows), Ctrl+C terminates the process abruptly — acceptable for dev use.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->running = false;
                $this->dispatcher?->stop();
            };
            pcntl_signal(2,  $stop); // SIGINT
            pcntl_signal(15, $stop); // SIGTERM
        }

        // ── Inline subscribers ────────────────────────────────────────────────
        // BridgeEventListener (wildcard) — logs all events except heartbeats.
        // HeartbeatListener handles heartbeats separately to avoid double output.
        $logger = new class implements BridgeEventListener {
            public function onBridgeEvent(BridgeEvent $event): void
            {
                if ('heartbeat' === $event->type) {
                    return; // handled by HeartbeatListener below
                }

                $msg     = is_string($event->payload['message'] ?? null)
                    ? $event->payload['message']
                    : json_encode($event->payload, \JSON_UNESCAPED_UNICODE);
                $preview = substr((string) $msg, 0, 120);
                echo sprintf("  [%s] \033[35m%s\033[0m %s\n", date('H:i:s'), $event->type, $preview);
            }
        };

        $heartbeat = new class implements HeartbeatListener {
            private int $count = 0;

            public function onHeartbeat(BridgeEvent $event): void
            {
                ++$this->count;
                if (0 === $this->count % 10) {
                    echo sprintf("  [%s] \033[36mheartbeat\033[0m ×%d\n", date('H:i:s'), $this->count);
                }
            }
        };

        $restartGuard = new class ($io) implements RestartApprovalHandler {
            public function __construct(private readonly SymfonyStyle $io) {}

            public function onApproveRestart(ApproveRestartRequest $request): bool
            {
                $this->io->warning("approve_restart requested (id={$request->requestId}) — rejected.");

                return false;
            }
        };

        // ── Reconnect loop ────────────────────────────────────────────────────
        $io->title('Bridge bundle — live listener');
        $io->comment("Target: {$url}");
        $io->comment('Press Ctrl+C to stop.');

        while ($this->running) {
            $connected = false;

            try {
                $io->comment('Connecting…');
                $conn = $this->factory->create($url)->ws()->connect();

                $this->dispatcher = new WsEventDispatcher($conn);
                $this->dispatcher
                    ->subscribe($logger)
                    ->subscribe($heartbeat)
                    ->subscribe($restartGuard);

                $snap = $this->dispatcher->snapshot();
                $io->success(sprintf(
                    'Connected — session=%s  slots=%d  wsConnected=%s',
                    $snap->sessionId,
                    count($snap->slots),
                    $snap->wsConnected ? 'yes' : 'no',
                ));

                $currentDelay = $baseDelay;
                $connected    = true;

                $this->dispatcher->listen();
            } catch (WsAuthException $e) {
                $io->error('Authentication failed — check archi_bridge.admin_token: '.$e->getMessage());

                return Command::FAILURE;
            } catch (WsConnectionException $e) {
                $io->warning(sprintf('Could not connect: %s', $e->getMessage()));
            }

            if ($connected) {
                $io->comment('Connection closed.');
            }

            // sleep() is interrupted early by SIGINT on Linux (returns remaining seconds).
            // The while condition exits the loop on the next iteration if !running.
            sleep($currentDelay);
            $currentDelay = min($currentDelay * 2, 60);
        }

        $io->info('Listener stopped.');

        return Command::SUCCESS;
    }
}
