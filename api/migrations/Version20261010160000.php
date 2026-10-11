<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.16: the social achievements, after the existing ones. Idempotent (ON CONFLICT): an achievement already
 * there under the same key is left as the admins made it.
 *
 * Story 43.18: the data is written here rather than imported from the source, so editing the definitions later never
 * changes this migration; and they are seeded inactive, so the hourly recompute cannot grant them silently before
 * `community:achievements:recompute --notify --activate=...` activates them and notifies the grants.
 */
final class Version20261010160000 extends AbstractMigration
{
    private const array DEFINITIONS = [
        ['key' => 'friends_played_5', 'name' => 'Les Goonies', 'description' => 'Jouer avec 5 amis différents.', 'rule' => '{"op":"all","rules":[{"fact":"distinctFriendsPlayedWith","operator":">=","value":5}]}'],
        ['key' => 'same_partner_3', 'name' => "L'Arme fatale", 'description' => 'Terminer 3 parties avec la même personne.', 'rule' => '{"op":"all","rules":[{"fact":"maxFinishedWithSamePerson","operator":">=","value":3}]}'],
        ['key' => 'weekly_duel_won', 'name' => "Il ne peut en rester qu'un", 'description' => 'Gagner un duel hebdo entre amis.', 'rule' => '{"op":"all","rules":[{"fact":"weeklyDuelsWon","operator":">=","value":1}]}'],
    ];

    public function getDescription(): string
    {
        return 'Story 43.16: seed the social achievements (inactive)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::DEFINITIONS as $definition) {
            $this->addSql(
                'INSERT INTO community_achievement_definition '
                .'(id, achievement_key, name, description, rule, active, position, created_at, updated_at) '
                .'VALUES (:id, :key, :name, :description, :rule, false, '
                .'(SELECT COALESCE(MAX(position), 0) + 1 FROM community_achievement_definition), NOW(), NOW()) '
                .'ON CONFLICT (achievement_key) DO NOTHING',
                [
                    'id' => bin2hex(random_bytes(16)),
                    'key' => $definition['key'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'rule' => $definition['rule'],
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DEFINITIONS as $definition) {
            $this->addSql('DELETE FROM community_achievement_definition WHERE achievement_key = :key', ['key' => $definition['key']]);
        }
    }
}
