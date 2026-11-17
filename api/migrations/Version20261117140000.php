<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Story 55.3: a tracked link can be a partner's, and every existing partner gets its own. The code rule is the one of
 * `PartnerLinkCode`, written here again: a migration never depends on application code that may change.
 */
final class Version20261117140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Content: partner tracked links, one for each existing partner';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tracked_link ADD partner_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_tracked_link_partner ON tracked_link (partner_id)');
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $this->backfillPartnerLinks();
    }

    /** Public for its test: a link for each partner that has none, with a free `partenaire-{name}` code. */
    public function backfillPartnerLinks(): void
    {
        $taken = array_fill_keys(array_map(strval(...), $this->connection->createQueryBuilder()->select('code')->from('tracked_link')->executeQuery()->fetchFirstColumn()), true);
        $linked = array_fill_keys(array_map(strval(...), $this->connection->createQueryBuilder()->select('partner_id')->from('tracked_link')->where('partner_id IS NOT NULL')->executeQuery()->fetchFirstColumn()), true);
        $partners = $this->connection->createQueryBuilder()
            ->select('id', 'name', 'url', 'active')
            ->from('partner')
            ->orderBy('position')
            ->addOrderBy('created_at')
            ->executeQuery()
            ->fetchAllAssociative();
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:sP');
        foreach ($partners as $partner) {
            $id = $partner['id'];
            $name = $partner['name'];
            $url = $partner['url'];
            if (!is_string($id) || !is_string($name) || !is_string($url) || isset($linked[$id])) {
                continue;
            }
            $attempt = 1;
            while (isset($taken[self::code($name, $attempt)])) {
                ++$attempt;
            }
            $code = self::code($name, $attempt);
            $taken[$code] = true;
            $this->connection->insert('tracked_link', [
                'id' => bin2hex(random_bytes(16)),
                'code' => $code,
                'label' => 'Partenaire : '.trim($name),
                'target' => trim($url),
                'active' => true === $partner['active'] || 1 === $partner['active'] || '1' === $partner['active'] || 't' === $partner['active'],
                'created_at' => $now,
                'updated_at' => $now,
                'partner_id' => $id,
            ], ['active' => 'boolean']);
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM tracked_link_visit WHERE link_id IN (SELECT id FROM tracked_link WHERE partner_id IS NOT NULL)');
        $this->addSql('DELETE FROM tracked_link WHERE partner_id IS NOT NULL');
        $this->addSql('DROP INDEX uniq_tracked_link_partner');
        $this->addSql('ALTER TABLE tracked_link DROP partner_id');
    }

    private static function code(string $name, int $attempt): string
    {
        $slug = (string) new AsciiSlugger()->slug($name)->lower();
        $base = '' === $slug ? 'partenaire' : 'partenaire-'.$slug;
        $suffix = $attempt > 1 ? '-'.$attempt : '';

        return rtrim(substr($base, 0, 40 - strlen($suffix)), '-').$suffix;
    }
}
