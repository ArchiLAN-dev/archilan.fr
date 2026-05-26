<?php

declare(strict_types=1);

namespace Archilan\BridgeClientBundle\Command;

use Archilan\BridgeClient\Ws\Exception\WsAuthException;
use Archilan\BridgeClient\Ws\Exception\WsConnectionException;
use Archilan\BridgeClient\Ws\WsEventDispatcher;
use Archilan\BridgeClientBundle\Ws\WsDispatcherFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long-running worker that stays connected to one bridge instance indefinitely.
 *
 * On connection loss (bridge restart, network glitch) it reconnects automatically
 * with exponential backoff (default 5 s → 10 s → 20 s … capped at 60 s).
 * On SIGINT / SIGTERM (Ctrl+C or systemd stop) it exits cleanly after the current
 * listen loop iteration ends.
 *
 * Run one process per active session:
 *
 *   php bin/console archilan:bridge:listen http://localhost:25001
 *   php bin/console archilan:bridge:listen http://localhost:25002 --retry-delay=10
 *
 * Authentication errors are fatal and do not trigger a reconnect.
 */
#[AsCommand(
    name: 'archilan:bridge:listen',
    description: 'Start the bridge WebSocket listener for a specific session (reconnects automatically).',
)]
final class BridgeListenCommand extends Command implements SignalableCommandInterface
{
    private bool $running = true;

    private ?WsEventDispatcher $dispatcher = null;

    public function __construct(private readonly WsDispatcherFactory $factory)
    {
        parent::__construct();
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [2, 15]; // SIGINT, SIGTERM — literals for Windows compatibility (pcntl constants undefined)
    }

    /**
     * Called by Symfony Console when SIGINT or SIGTERM is received.
     *
     * Sets the stop flag and closes the current connection so that listen()
     * returns promptly rather than waiting for the next inbound frame.
     * Returning false lets execute() return normally instead of force-killing the process.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->running = false;
        $this->dispatcher?->stop();

        return false;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'bridge-url',
                InputArgument::REQUIRED,
                'HTTP base URL of the target bridge (e.g. http://localhost:25001).',
            )
            ->addOption(
                'retry-delay',
                null,
                InputOption::VALUE_REQUIRED,
                'Initial seconds to wait between reconnection attempts (doubles on each failure, max 60).',
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

        $io->title('Archilan Bridge — WebSocket listener');
        $io->comment("Target: {$url}");
        $io->comment('Press Ctrl+C to stop.');

        while ($this->running) {
            $connected = false;

            try {
                $io->comment('Connecting…');
                $this->dispatcher = $this->factory->createForUrl($url);

                $snap = $this->dispatcher->snapshot();
                $io->success(sprintf(
                    'Connected — session=%s  slots=%d  wsConnected=%s',
                    $snap->sessionId,
                    count($snap->slots),
                    $snap->wsConnected ? 'yes' : 'no',
                ));

                $currentDelay = $baseDelay; // reset backoff after a successful connection
                $connected    = true;

                $this->dispatcher->listen(); // blocks until connection closes or stop() is called
            } catch (WsAuthException $e) {
                $io->error('Authentication failed — check archi_bridge.admin_token: '.$e->getMessage());

                return Command::FAILURE; // permanent error, never retry
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
