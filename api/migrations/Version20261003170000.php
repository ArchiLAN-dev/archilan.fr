<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.7: the cosmetics shop - items on sale, and what members bought.
 */
final class Version20261003170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.7: shop_item, owned_cosmetic';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_item (id VARCHAR(32) NOT NULL, type VARCHAR(12) NOT NULL, cosmetic_key VARCHAR(64) NOT NULL, price INT NOT NULL, available_from TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, available_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE owned_cosmetic (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, type VARCHAR(12) NOT NULL, cosmetic_key VARCHAR(64) NOT NULL, acquired_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_owned_cosmetic ON owned_cosmetic (user_id, type, cosmetic_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE owned_cosmetic');
        $this->addSql('DROP TABLE shop_item');
    }
}
