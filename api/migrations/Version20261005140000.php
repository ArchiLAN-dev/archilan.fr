<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.14: a temporary promotion on a shop item, and the banner announcing a HelloAsso promotion.
 */
final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.14: shop_item promotion columns, shop_announcement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_item ADD promo_price INT DEFAULT NULL');
        $this->addSql('ALTER TABLE shop_item ADD promo_starts_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE shop_item ADD promo_ends_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE TABLE shop_announcement (id VARCHAR(32) NOT NULL, message VARCHAR(200) NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shop_announcement');
        $this->addSql('ALTER TABLE shop_item DROP promo_ends_at');
        $this->addSql('ALTER TABLE shop_item DROP promo_starts_at');
        $this->addSql('ALTER TABLE shop_item DROP promo_price');
    }
}
