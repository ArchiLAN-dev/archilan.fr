<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Community\Domain\DefaultAchievementDefinitions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: DB-backed configurable achievements (epic 30, story 30.16). Moves the code-defined catalogue
 * into community_achievement_definition; postUp seeds the 9 historical entries as one-condition rule trees.
 */
final class Version20260618170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: configurable achievement definitions (epic 30, story 30.16)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_achievement_definition (id VARCHAR(32) NOT NULL, achievement_key VARCHAR(64) NOT NULL, name VARCHAR(191) NOT NULL, description TEXT NOT NULL, rule JSON NOT NULL, active BOOLEAN NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_achievement_key ON community_achievement_definition (achievement_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_achievement_definition');
    }

    public function postUp(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
        $position = 1;
        foreach (DefaultAchievementDefinitions::all() as $def) {
            $this->connection->insert('community_achievement_definition', [
                'id' => bin2hex(random_bytes(16)),
                'achievement_key' => $def['key'],
                'name' => $def['name'],
                'description' => $def['description'],
                'rule' => json_encode($def['rule'], \JSON_THROW_ON_ERROR),
                'active' => true,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'active' => \PDO::PARAM_BOOL,
                'position' => \PDO::PARAM_INT,
            ]);
            ++$position;
        }
    }
}