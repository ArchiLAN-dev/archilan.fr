<?php

declare(strict_types=1);

namespace App\Command;

use App\Subscriber\TestFeedSubscriber;
use App\Subscriber\TestHeartbeatSubscriber;
use Archilan\BridgeClientBundle\Bridge\BridgeClientFactory;
use Archilan\BridgeClientBundle\Bridge\BridgeClientPool;
use Archilan\BridgeClientBundle\Ws\WsDispatcherFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Integration test for the bridge Symfony bundle.
 *
 * DI wiring test (no bridge required):
 *   php bin/console test:bridge
 *
 * Full live test against a running bridge:
 *   php bin/console test:bridge http://localhost:25001
 */
#[AsCommand(
    name: 'test:bridge',
    description: 'Integration test for the Archilan bridge bundle (DI wiring + optional live tests).',
)]
final class TestBridgeCommand extends Command
{
    public function __construct(
        private readonly BridgeClientFactory $factory,
        private readonly BridgeClientPool $pool,
        private readonly WsDispatcherFactory $dispatcherFactory,
        // Injected by Symfony; also subscribed inside WsDispatcherFactory via tagged_iterator.
        // Both references point to the same container singleton, so we can set ->dispatcher
        // on the instance after the WsEventDispatcher is created.
        private readonly TestFeedSubscriber $feedSub,
        private readonly TestHeartbeatSubscriber $hbSub,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'bridge-url',
            InputArgument::OPTIONAL,
            'HTTP base URL of the bridge (e.g. http://localhost:25001). Omit to run DI checks only.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $url = $input->getArgument('bridge-url');

        $io->title('Bridge bundle — integration tests');

        // ── DI wiring ──────────────────────────────────────────────────────────
        // Reaching here proves Symfony resolved all constructor args:
        //   BridgeClientFactory     bundle service, explicit args in services.php
        //   BridgeClientPool        bundle service, explicit args in services.php
        //   WsDispatcherFactory     bundle service, tagged_iterator injects subscribers
        //   TestFeedSubscriber      App service, autoconfigure → archi_bridge.ws_subscriber
        //   TestHeartbeatSubscriber App service, autoconfigure → archi_bridge.ws_subscriber
        $io->section('DI wiring');
        $io->success('BridgeClientFactory, BridgeClientPool, WsDispatcherFactory → wired.');
        $io->success('TestFeedSubscriber, TestHeartbeatSubscriber → autoconfigured as archi_bridge.ws_subscriber.');

        if (!is_string($url) || '' === $url) {
            $io->comment('No bridge URL — skipping live tests.');
            $io->comment('Pass a URL to run the full suite: php bin/console test:bridge http://localhost:25001');

            return Command::SUCCESS;
        }

        // ── BridgeClientPool — instance caching ────────────────────────────────
        $io->section('BridgeClientPool — instance caching');

        $a = $this->pool->get('slot', $url);
        $b = $this->pool->get('slot', $url);
        if ($a !== $b) {
            $io->error('Expected the same instance for the same sessionId.');

            return Command::FAILURE;
        }
        $io->success('get() returns the same instance for an identical sessionId.');

        $this->pool->release('slot');
        $c = $this->pool->get('slot', $url);
        if ($a === $c) {
            $io->error('Expected a fresh instance after release().');

            return Command::FAILURE;
        }
        $io->success('get() returns a fresh instance after release().');

        // ── Health check ───────────────────────────────────────────────────────
        $io->section('Health check');
        try {
            $health = $this->factory->create($url)->room()->health();
        } catch (\Exception $e) {
            $io->error('health() failed: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf(
            'status=%s  wsConnected=%s  sessionId=%s',
            $health->status,
            $health->wsConnected ? 'yes' : 'no',
            $health->sessionId,
        ));

        // ── WebSocket — snapshot + typed subscriber dispatch ───────────────────
        $io->section('WebSocket — snapshot + typed subscriber dispatch');
        try {
            $dispatcher = $this->dispatcherFactory->createForUrl($url);
            $snap = $dispatcher->snapshot();
            $io->text(sprintf(
                'Snapshot: sessionId=%s  slots=%d  wsConnected=%s',
                $snap->sessionId,
                count($snap->slots),
                $snap->wsConnected ? 'yes' : 'no',
            ));

            // Wire the stop signal: feedSub is the same singleton subscribed inside dispatcher.
            $this->feedSub->dispatcher = $dispatcher;

            $dispatcher->sendCommand('!help'); // triggers AP server → feed events on WS
            $dispatcher->listen(); // blocks until feedSub calls stop() after 2 events

            $io->success(sprintf('Feed events received: %d.', $this->feedSub->feedCount));
            $io->success(sprintf('Heartbeat events — TestFeedSubscriber: %d.', $this->feedSub->heartbeatCount));
            $io->success(sprintf('Heartbeat events — TestHeartbeatSubscriber: %d.', $this->hbSub->count));

            if ($this->feedSub->heartbeatCount !== $this->hbSub->count) {
                $io->error('Heartbeat fan-out mismatch: both subscribers should fire equally.');

                return Command::FAILURE;
            }
            $io->success('Fan-out verified: both heartbeat subscribers fired equally.');
        } catch (\Exception $e) {
            $io->error('WebSocket test failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('All tests passed.');

        return Command::SUCCESS;
    }
}
