<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Community\Domain\Service\SocialAchievementDefinitions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.16: the social achievements, after the existing ones. Idempotent (ON CONFLICT): an achievement already
 * there under the same key is left as the admins made it.
 */
final class Version20261010160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.16: seed the social achievements';
    }

    public function up(Schema $schema): void
    {
        foreach (SocialAchievementDefinitions::all() as $definition) {
            $this->addSql(
                'INSERT INTO community_achievement_definition '
                .'(id, achievement_key, name, description, rule, active, position, created_at, updated_at) '
                .'VALUES (:id, :key, :name, :description, :rule, true, '
                .'(SELECT COALESCE(MAX(position), 0) + 1 FROM community_achievement_definition), NOW(), NOW()) '
                .'ON CONFLICT (achievement_key) DO NOTHING',
                [
                    'id' => bin2hex(random_bytes(16)),
                    'key' => $definition['key'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'rule' => json_encode($definition['rule'], \JSON_THROW_ON_ERROR),
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (SocialAchievementDefinitions::all() as $definition) {
            $this->addSql('DELETE FROM community_achievement_definition WHERE achievement_key = :key', ['key' => $definition['key']]);
        }
    }
}
