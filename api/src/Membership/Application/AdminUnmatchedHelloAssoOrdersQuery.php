<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Payments\Application\HelloAssoConfig;
use Doctrine\DBAL\Connection;

final readonly class AdminUnmatchedHelloAssoOrdersQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb
            ->select(
                'o.helloasso_order_id',
                'o.form_slug',
                'o.amount_cents',
                'o.payer_email',
                'o.payer_first_name',
                'o.payer_last_name',
                'o.paid_at',
                'o.synced_at',
            )
            ->from('hello_asso_order', 'o')
            ->where($qb->expr()->eq('o.form_type', ':formType'))
            ->andWhere($qb->expr()->eq('o.status', ':status'))
            ->andWhere('NOT EXISTS (SELECT 1 FROM memberships m WHERE m.helloasso_order_id = CAST(o.helloasso_order_id AS TEXT))')
            ->orderBy('o.paid_at', 'DESC')
            ->setParameter('formType', HelloAssoConfig::FORM_TYPE_MEMBERSHIP)
            ->setParameter('status', 'Processed')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
