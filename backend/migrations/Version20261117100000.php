<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Devis initial : détail du devis signé conservé, droit « Saisir le devis initial » donné aux groupes qui peuvent modifier le devis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD signed_quote_detail JSON DEFAULT NULL');
        $this->addSql("UPDATE app_group SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'signed.edit') WHERE JSON_CONTAINS(permissions, '\"finance.edit\"') AND NOT JSON_CONTAINS(permissions, '\"signed.edit\"')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP signed_quote_detail');
        $this->addSql("UPDATE app_group SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'signed.edit'))) WHERE JSON_CONTAINS(permissions, '\"signed.edit\"')");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
