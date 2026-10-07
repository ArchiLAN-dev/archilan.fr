<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.30: an uploaded video frame's optional shade (its dark parts, laid in `multiply`).
 */
final class Version20261007160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.30: avatar_frame.shade_webm_key, shade_mp4_key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE avatar_frame ADD shade_webm_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE avatar_frame ADD shade_mp4_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE avatar_frame DROP shade_mp4_key');
        $this->addSql('ALTER TABLE avatar_frame DROP shade_webm_key');
    }
}
