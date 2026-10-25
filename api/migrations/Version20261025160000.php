<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.53: a one-time code may open the blind test's animation for an admin, with no event attached.
 */
final class Version20261025160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.53: blind_test_login_code.event_id nullable (animation codes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blind_test_login_code ALTER event_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM blind_test_login_code WHERE event_id IS NULL');
        $this->addSql('ALTER TABLE blind_test_login_code ALTER event_id SET NOT NULL');
    }
}
