<?php

declare(strict_types=1);

namespace App\Community\Presentation\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Story 40.2: the site's VAPID key pair for browser pushes, to paste into `envs/api.env` once. Changing
 * it later invalidates every device already subscribed: they have to turn notifications on again.
 */
#[AsCommand(name: 'app:web-push:generate-keys', description: 'Generate the VAPID key pair for browser push notifications.')]
final class GenerateWebPushKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = VAPID::createVapidKeys();
        $io = new SymfonyStyle($input, $output);
        $publicKey = $keys['publicKey'] ?? null;
        $privateKey = $keys['privateKey'] ?? null;
        if (!is_string($publicKey) || !is_string($privateKey)) {
            $io->error('The VAPID key pair could not be generated.');

            return Command::FAILURE;
        }

        $io->text('Add to envs/api.env (keep the private key secret), then restart the API and the worker:');
        $io->newLine();
        $output->writeln('WEB_PUSH_VAPID_PUBLIC_KEY='.$publicKey);
        $output->writeln('WEB_PUSH_VAPID_PRIVATE_KEY='.$privateKey);
        $output->writeln('WEB_PUSH_SUBJECT=mailto:contact@archilan.fr');
        $io->newLine();
        $io->warning('Generate them once: a new pair logs every subscribed device out of pushes.');

        return Command::SUCCESS;
    }
}
