<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Domain\EmailConfirmationTokenRepositoryInterface;
use App\Identity\Domain\PasswordResetTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:auth:cleanup-tokens',
    description: 'Prune expired/consumed email-confirmation and password-reset tokens.',
)]
final class CleanupAuthTokensCommand extends Command
{
    public function __construct(
        private readonly EmailConfirmationTokenRepositoryInterface $emailConfirmationTokens,
        private readonly PasswordResetTokenRepositoryInterface $passwordResetTokens,
        private readonly LoggerInterface $logger,
        private readonly int $tokenConsumedGraceDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $consumedBefore = $now->modify(sprintf('-%d days', $this->tokenConsumedGraceDays));

        $emails = $this->emailConfirmationTokens->deleteStale($now, $consumedBefore);
        $resets = $this->passwordResetTokens->deleteStale($now, $consumedBefore);

        $this->logger->info('auth.cleanup_email_confirmation_tokens', ['deleted' => $emails]);
        $this->logger->info('auth.cleanup_password_reset_tokens', ['deleted' => $resets]);

        $output->writeln(sprintf('Deleted %d email-confirmation and %d password-reset token(s).', $emails, $resets));

        return Command::SUCCESS;
    }
}
