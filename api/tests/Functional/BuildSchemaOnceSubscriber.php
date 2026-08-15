<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Builds the test schema once per phpunit process, on the first functional test it sees.
 *
 * Before story 33.25 every {@see FunctionalTestCase} test rebuilt the whole schema in setUp():
 * DROP SCHEMA (~110 ms) + SchemaTool::createSchema (~300 ms, 104 statements for 50 entities) =
 * ~420 ms per test, ~55% of the functional suite, producing a byte-identical schema every time.
 * Now it happens once and tests only wipe the rows.
 *
 * Two properties are deliberate:
 *
 * - It still drops and recreates at *every process start*, so a mapping change is picked up exactly
 *   as reliably as before. There is no stale-schema window and nothing to invalidate by hand.
 * - It is lazy. The `unit` testsuite is DB-free and must stay that way, so nothing here touches
 *   Postgres until a test that actually needs a database is about to run.
 *
 * `doctrine:database:create --if-not-exists` runs first because a ParaTest worker's database
 * (`archilan_test<TEST_TOKEN>`, see config/packages/doctrine.yaml) does not exist yet the first time
 * that worker starts.
 */
final class BuildSchemaOnceSubscriber implements PreparationStartedSubscriber
{
    private bool $built = false;

    public function notify(PreparationStarted $event): void
    {
        if ($this->built) {
            return;
        }

        $test = $event->test();
        if (!$test instanceof TestMethod || !is_subclass_of($test->className(), FunctionalTestCase::class)) {
            return;
        }

        $this->built = true;
        $this->build();
    }

    private function build(): void
    {
        $kernel = new Kernel('test', filter_var($_SERVER['APP_DEBUG'] ?? true, \FILTER_VALIDATE_BOOL));
        $kernel->boot();

        try {
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $application->run(
                new ArrayInput(['command' => 'doctrine:database:create', '--if-not-exists' => true]),
                new NullOutput(),
            );

            $entityManager = $this->entityManager($kernel);
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

            $entityManager->getConnection()->executeStatement('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');

            if ([] !== $metadata) {
                new SchemaTool($entityManager)->createSchema($metadata);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    private function entityManager(Kernel $kernel): EntityManagerInterface
    {
        $registry = $kernel->getContainer()->get('doctrine');
        if (!$registry instanceof ManagerRegistry) {
            throw new \RuntimeException('The "doctrine" service is not a ManagerRegistry.');
        }

        $entityManager = $registry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \RuntimeException('The default Doctrine manager is not an EntityManagerInterface.');
        }

        return $entityManager;
    }
}
