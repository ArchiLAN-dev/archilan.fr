<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.15: weekly quests written by the admins. The three quests of story 41.6 become quests in the draw, under
 * the ids their ledger keys already use (`quest:{week}:{quest}:{member}`), and the weeks they served (2026-W40,
 * 2026-W41) are frozen with them so nothing is paid twice nor drawn again.
 */
final class Version20261005200000 extends AbstractMigration
{
    private const array QUESTS = [
        ['reach_a_goal', 'Atteindre un goal', 40, 'goals'],
        ['play_with_someone_new', 'Jouer avec quelqu\'un de nouveau', 30, 'newPartners'],
        ['play_a_weekly', 'Faire une hebdo', 30, 'weeklies'],
    ];

    private const array SERVED_WEEKS = ['2026-W40', '2026-W41'];

    public function getDescription(): string
    {
        return 'Story 41.15: quest_definition, quest_week_entry, quest_week_draw, wallet_setting; the three quests of 41.6';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quest_definition (id VARCHAR(32) NOT NULL, title VARCHAR(80) NOT NULL, description VARCHAR(200) NOT NULL, reward INT NOT NULL, objectives JSON NOT NULL, in_draw BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE quest_week_entry (id VARCHAR(32) NOT NULL, week_key VARCHAR(8) NOT NULL, quest_id VARCHAR(32) NOT NULL, origin VARCHAR(8) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_quest_week_entry ON quest_week_entry (week_key, quest_id)');
        $this->addSql('CREATE TABLE quest_week_draw (week_key VARCHAR(8) NOT NULL, drawn_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (week_key))');
        $this->addSql('CREATE TABLE wallet_setting (setting_key VARCHAR(64) NOT NULL, value VARCHAR(255) NOT NULL, PRIMARY KEY (setting_key))');

        foreach (self::QUESTS as [$id, $title, $reward, $metric]) {
            $this->addSql(
                'INSERT INTO quest_definition (id, title, description, reward, objectives, in_draw, created_at) VALUES (:id, :title, \'\', :reward, :objectives, true, NOW())',
                ['id' => $id, 'title' => $title, 'reward' => $reward, 'objectives' => json_encode([['metric' => $metric, 'target' => 1]], \JSON_THROW_ON_ERROR)],
            );
        }
        foreach (self::SERVED_WEEKS as $week) {
            $this->addSql('INSERT INTO quest_week_draw (week_key, drawn_at) VALUES (:week, NOW())', ['week' => $week]);
            foreach (self::QUESTS as $position => [$id]) {
                $this->addSql(
                    'INSERT INTO quest_week_entry (id, week_key, quest_id, origin, position, created_at) VALUES (:entry, :week, :quest, \'drawn\', :position, NOW())',
                    ['entry' => md5($week.$id), 'week' => $week, 'quest' => $id, 'position' => $position],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wallet_setting');
        $this->addSql('DROP TABLE quest_week_draw');
        $this->addSql('DROP TABLE quest_week_entry');
        $this->addSql('DROP TABLE quest_definition');
    }
}
