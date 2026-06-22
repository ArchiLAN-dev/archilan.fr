<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleGroup;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: seed the `event_finisher` achievement (story 30.32) — "reach a goal in an ArchiLAN event".
 * Idempotent (ON CONFLICT) so it is a no-op on fresh installs where the 30.16 seed already created it.
 */
final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: seed the event_finisher achievement (story 30.32)';
    }

    public function up(Schema $schema): void
    {
        $rule = json_encode([
            'op' => AchievementRuleGroup::OP_ALL,
            'rules' => [[
                'fact' => AchievementMetricCatalog::FACT_EVENTS_WITH_GOAL,
                'operator' => AchievementOperator::GreaterOrEqual->value,
                'value' => 1,
            ]],
        ], \JSON_THROW_ON_ERROR);

        $this->addSql(
            'INSERT INTO community_achievement_definition '
            .'(id, achievement_key, name, description, rule, active, position, created_at, updated_at) '
            .'VALUES (:id, :key, :name, :description, :rule, true, '
            .'(SELECT COALESCE(MAX(position), 0) + 1 FROM community_achievement_definition), NOW(), NOW()) '
            .'ON CONFLICT (achievement_key) DO NOTHING',
            [
                'id' => bin2hex(random_bytes(16)),
                'key' => 'event_finisher',
                'name' => 'Compétiteur',
                'description' => 'Atteindre un objectif lors d\'un événement ArchiLAN.',
                'rule' => $rule,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM community_achievement_definition WHERE achievement_key = 'event_finisher'");
    }
}
