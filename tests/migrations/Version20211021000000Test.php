<?php

namespace go1\enrolment\tests\migrations;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Migrator;
use go1\enrolment\tests\EnrolmentTestCase;

class Version20211021000000Test extends EnrolmentTestCase
{
    private Migrator   $testSubject;
    private Connection $connection;

    public function setUp(): void
    {
        $app = $this->getApp();
        $this->testSubject = $app[DependencyFactory::class]->getMigrator();
        $this->connection = $app['dbs']['enrolment'];
    }

    public function testUp()
    {
        $this->testSubject->migrate(20211021000000);
        $this->assertTrue($this->connection->getSchemaManager()->tablesExist('recurring'));
        $recurring = $this->connection->getSchemaManager()->listTableColumns('recurring');
        $columns = [
            'id', 'frequency_type', 'frequency_interval', 'next_assigned_at', 'next_duedate_at',
            'created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($columns as $column) {
            $this->assertArrayHasKey($column, $recurring);
        }

        $this->assertTrue($this->connection->getSchemaManager()->tablesExist('recurring_plan'));
        $recurringPlan = $this->connection->getSchemaManager()->listTableColumns('recurring_plan');
        $columns = [
            'id', 'scheduler_job_id', 'plan_id', 'recurring_id', 'lo_id', 'portal_id', 'group_id',
            'created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($columns as $column) {
            $this->assertArrayHasKey($column, $recurringPlan);
        }
    }

    public function testDown()
    {
        $this->testSubject->migrate(0);
        $this->assertFalse($this->connection->getSchemaManager()->tablesExist('recurring'));
        $this->assertFalse($this->connection->getSchemaManager()->tablesExist('recurring_plan'));
    }
}
