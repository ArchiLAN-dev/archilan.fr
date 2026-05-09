<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Application\DddArchitectureValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:architecture:ddd',
    description: 'Validate DDD bounded-context and layer boundaries.',
)]
final class ValidateDddArchitectureCommand extends Command
{
    public function __construct(
        private readonly DddArchitectureValidator $validator,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->validator->validate($this->kernel->getProjectDir());

        if ($report->isSuccessful()) {
            $io->success('DDD architecture boundaries are respected.');

            return Command::SUCCESS;
        }

        $io->error('DDD architecture violations detected.');
        $io->listing($report->violations());

        return Command::FAILURE;
    }
}
