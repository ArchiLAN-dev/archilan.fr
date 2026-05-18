<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Handler;

use App\WeeklyRuns\Application\Message\GenerateWeeklyRunsMessage;
use App\WeeklyRuns\Domain\WeeklyRun;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateWeeklyRunsMessageHandler
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GenerateWeeklyRunsMessage $message): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $weekYear = (int) $now->format('o');
        $weekNumber = (int) $now->format('W');
        $seed = (string) random_int(1, 2_147_483_647);

        $templateIds = $this->connection->createQueryBuilder()
            ->select('wt.id')
            ->from('weekly_templates', 'wt')
            ->where('wt.is_active = :active')
            ->setParameter('active', true, ParameterType::BOOLEAN)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($templateIds as $templateId) {
            if (!is_string($templateId)) {
                continue;
            }

            $countRaw = $this->connection->createQueryBuilder()
                ->select('COUNT(wr.id)')
                ->from('weekly_runs', 'wr')
                ->where('wr.template_id = :templateId')
                ->andWhere('wr.week_year = :year')
                ->andWhere('wr.week_number = :week')
                ->setParameter('templateId', $templateId)
                ->setParameter('year', $weekYear)
                ->setParameter('week', $weekNumber)
                ->executeQuery()
                ->fetchOne();

            if (false !== $countRaw && is_numeric($countRaw) && 0 < (int) $countRaw) {
                continue;
            }

            $run = new WeeklyRun(
                id: bin2hex(random_bytes(8)),
                templateId: $templateId,
                weekYear: $weekYear,
                weekNumber: $weekNumber,
                seed: $seed,
                status: WeeklyRun::STATUS_ACTIVE,
                startedAt: $now,
                createdAt: $now,
            );

            $this->entityManager->persist($run);
            // Flush per template so a unique-constraint race on one template does not roll back the others.
            $this->entityManager->flush();
        }
    }
}
