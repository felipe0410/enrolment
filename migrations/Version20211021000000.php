<?php

namespace go1\enrolment\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

class Version20211021000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('recurring')) {
            $table = $schema->createTable('recurring');
            $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $table->addColumn('frequency_type', Types::STRING);
            $table->addColumn('frequency_interval', Types::INTEGER);
            $table->addColumn('next_assigned_at', Types::DATETIME_MUTABLE);
            $table->addColumn('next_duedate_at', Types::DATETIME_MUTABLE);
            $table->addColumn('created_by', Types::INTEGER);
            $table->addColumn('updated_by', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('deleted_by', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP', 'notnull' => false]);
            $table->addColumn('deleted_at', Types::DATETIME_MUTABLE, ['length' => 6, 'notnull' => false]);
            $table->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable('recurring_plan')) {
            $table = $schema->createTable('recurring_plan');
            $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $table->addColumn('scheduler_job_id', Types::INTEGER);
            $table->addColumn('plan_id', Types::INTEGER);
            $table->addColumn('recurring_id', Types::INTEGER);
            $table->addColumn('lo_id', Types::INTEGER);
            $table->addColumn('portal_id', Types::INTEGER);
            $table->addColumn('group_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('created_by', Types::INTEGER);
            $table->addColumn('updated_by', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('deleted_by', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP', 'notnull' => false]);
            $table->addColumn('deleted_at', Types::DATETIME_MUTABLE, ['length' => 6, 'notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['plan_id']);
            $table->addIndex(['lo_id']);
            $table->addIndex(['portal_id']);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('recurring');
        $schema->dropTable('recurring_plan');
    }
}
