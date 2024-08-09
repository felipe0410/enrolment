<?php

namespace go1\enrolment\controller;

use DateTime;
use DateTimeZone;
use go1\util\DateTime as Go1DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\DependencyFactory;
use go1\enrolment\Constants;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\plan\Plan;
use go1\util\plan\PlanTypes;
use go1\util\schema\EnrolmentSchema;
use go1\util\schema\InstallTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class InstallController
{
    use InstallTrait;

    private Connection $go1;
    private Connection $enrolment;
    private DependencyFactory $factory;

    public function __construct(Connection $db, Connection $enrolment, DependencyFactory $factory)
    {
        $this->go1 = $db;
        $this->enrolment = $enrolment;
        $this->factory = $factory;
    }

    public function install(Request $req): JsonResponse
    {
        $accessChecker = new AccessChecker();
        if (!$accessChecker->isAccountsAdmin($req)) {
            return new JsonResponse([], 403);
        }

        $installTable = true;

        $complianceUpliftSuggestedPlan = $req->request->get('compliance_uplift_suggested_plan');
        $limit = (int) $req->request->get('limit', 100);
        if ($complianceUpliftSuggestedPlan) {
            $installTable = false;
        }

        if ($installTable) {
            DB::install($this->enrolment, [
                fn (Schema $schema) => EnrolmentSchema::installManualRecord($schema),
                # Features in on dev, there's no real data yet, just drop and re-create new one.
                function (Schema $schema) {
                    if ($schema->hasTable('enrolment_manual')) {
                        $manual = $schema->getTable('enrolment_manual');
                        if (!$manual->hasColumn('instance_id')) {
                            $schema->dropTable('enrolment_manual');
                        }
                    }
                },
                fn (Schema $schema) => $this->addAttributes($schema),
            ]);
            DB::install($this->go1, [
                function (Schema $schema) {
                    EnrolmentSchema::install($schema);
                    $this->addRevisionData($schema);
                    $this->addRevisionParentEnrolmentId($schema);
                    $this->addRevisionTimestamp($schema);
                    $this->addParenEnrolment($schema);
                    $this->installPlanTables($schema);

                    if ($schema->hasTable('gc_enrolment_revision')) {
                        $revision = $schema->getTable('gc_enrolment_revision');
                        if ($revision->hasColumn('start_date')) {
                            $startDate = $revision->getColumn('start_date');
                            if ($startDate->getNotnull()) {
                                $startDate->setNotnull(false);
                            }
                        }

                        $columns = ['enrolment_id', 'parent_lo_id', 'pass'];
                        $indexes = $revision->getIndexes();
                        foreach ($columns as $column) {
                            $hasIndex = false;
                            foreach ($indexes as $index) {
                                if ($index->getColumns() == [$column]) {
                                    $hasIndex = true;
                                    break;
                                }
                            }

                            if (!$hasIndex) {
                                $revision->addIndex([$column]);
                            }
                        }
                    }
                },
                function (Schema $schema) {
                    if (!$schema->hasTable('gc_enrolment_transaction')) {
                        $map = $schema->createTable('gc_enrolment_transaction');
                        $map->addColumn('enrolment_id', Type::INTEGER, ['unsigned' => true]);
                        $map->addColumn('transaction_id', Type::INTEGER, ['unsigned' => true]);
                        $map->addColumn('payment_method', Type::STRING);
                        $map->addUniqueIndex(['enrolment_id', 'transaction_id']);
                        $map->addIndex(['enrolment_id']);
                        $map->addIndex(['transaction_id']);
                        $map->addIndex(['payment_method']);
                    }
                },
            ]);
        }

        if ($complianceUpliftSuggestedPlan) {
            $this->migrateSuggestedPlan($limit);
        }

        $this->factory->getMigrator()->migrate();

        return new JsonResponse([], 204);
    }

    private function addRevisionData(Schema $schema): void
    {
        $revision = $schema->getTable('gc_enrolment_revision');
        if (!$revision->hasColumn('data')) {
            $revision->addColumn('data', 'blob', ['notnull' => false]);
        }
    }

    private function addRevisionParentEnrolmentId(Schema $schema): void
    {
        $revision = $schema->getTable('gc_enrolment_revision');
        if (!$revision->hasColumn('parent_enrolment_id')) {
            $revision->addColumn('parent_enrolment_id', 'integer', ['unsigned' => true, 'notnull' => false]);
            $revision->addIndex(['parent_enrolment_id']);
        }
    }

    private function addRevisionTimestamp(Schema $schema): void
    {
        $revision = $schema->getTable('gc_enrolment_revision');
        if (!$revision->hasColumn('timestamp')) {
            $revision->addColumn('timestamp', 'integer', ['unsigned' => true, 'notnull' => false]);
            $revision->addIndex(['timestamp']);
        }
    }

    private function addParenEnrolment(Schema $schema): void
    {
        $enrolment = $schema->getTable('gc_enrolment');
        if (!$enrolment->hasColumn('parent_enrolment_id')) {
            $enrolment->addColumn('parent_enrolment_id', 'integer', ['unsigned' => true, 'notnull' => false, 'default' => 0]);
            $enrolment->addIndex(['parent_enrolment_id']);

            foreach ($enrolment->getIndexes() as $index) {
                if ($index->getColumns() == ['profile_id', 'parent_lo_id', 'lo_id', 'taken_instance_id']) {
                    $enrolment->dropIndex($index->getName());
                }
            }
            $enrolment->addUniqueIndex(['profile_id', 'parent_enrolment_id', 'lo_id', 'taken_instance_id']);
        }
    }

    private function addAttributes(Schema $schema): void
    {
        if ($schema->hasTable('enrolment_attributes')) {
            return;
        }

        $table = $schema->createTable('enrolment_attributes');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('enrolment_id', 'integer', ['unsigned' => true]);
        $table->addColumn('key', 'integer', ['unsigned' => true]);
        /** @see EnrolmentAttributes */
        $table->addColumn('value', 'blob');
        $table->addColumn('created', 'integer', ['unsigned' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['enrolment_id']);
        $table->addIndex(['key']);
    }

    private function installPlanTables(Schema $schema): void
    {
        if ($schema->hasTable('gc_plan')) {
            $table = $schema->getTable('gc_plan');

            if (!$table->hasColumn('instance_id')) {
                $table->addColumn('instance_id', Type::INTEGER, ['unsigned' => true, 'notnull' => false]);
                $table->addIndex(['instance_id']);
            }

            if (!$table->hasColumn('updated_at')) {
                $table->addColumn('updated_at', Types::DATETIME_MUTABLE, [
                    'notnull' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ]);
            }
        }

        if (!$schema->hasTable('gc_enrolment_plans')) {
            // create table `gc_enrolment_plans`
            $table = $schema->createTable('gc_enrolment_plans');
            $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $table->addColumn('enrolment_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('plan_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP', 'notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('gc_enrolment', ['enrolment_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION']);
            $table->addForeignKeyConstraint('gc_plan', ['plan_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION']);
            $table->addUniqueIndex(['plan_id', 'enrolment_id']);
        } else {
            // Migrate data from gc_ro to gc_enrolment_plans.
            if (!$this->go1->getDriver() instanceof Driver) {
                $count = $this->go1->fetchColumn('SELECT COUNT(*) FROM gc_enrolment_plans');
                if ($count == 0) {
                    $this->go1->executeQuery(
                        'INSERT INTO gc_enrolment_plans (enrolment_id, plan_id, created_at, updated_at)'
                        . ' ('
                        . '     SELECT source_id, target_id, gc_enrolment.start_date, gc_enrolment.start_date'
                        . '     FROM gc_ro'
                        . '     INNER JOIN gc_enrolment ON gc_ro.source_id = gc_enrolment.id'
                        . '     INNER JOIN gc_plan ON gc_ro.target_id = gc_plan.id'
                        . '     WHERE gc_ro.type = 900'
                        . ' )'
                    );
                }
            }
        }

        if (!$schema->hasTable('gc_plan_reference')) {
            // create table `gc_plan_reference`
            $table = $schema->createTable('gc_plan_reference');
            $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $table->addColumn('plan_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('source_type', Type::STRING);
            $table->addColumn('source_id', Type::INTEGER);
            $table->addColumn('status', Type::SMALLINT);
            $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP', 'notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['plan_id', 'source_type', 'source_id']);
            $table->addIndex(['source_type', 'source_id', 'status']);
            $table->addIndex(['plan_id']);
        }
    }

    private function migrateSuggestedPlan(int $limit): void
    {
        $q = $this->go1->executeQuery(
            'SELECT l.* FROM gc_enrolment_plans l' .
            '   INNER JOIN gc_plan p ON p.id = l.plan_id'.
            '   WHERE p.type = 3'. //PlanTypes::SUGGESTED
            '   LIMIT ' . $limit . ' OFFSET 0'
        );

        while ($plan = $q->fetch(DB::OBJ)) {
            $edges = $this->go1
                ->executeQuery('SELECT plan_id FROM gc_enrolment_plans WHERE enrolment_id = ?', [$plan->enrolment_id])
                ->fetchAll();

            if (count($edges) == 1) {// no type 1
                $this->go1->update('gc_plan', ['type' => PlanTypes::ASSIGN], ['id' => $plan->plan_id]);
            } else {
                if (count($edges) > 1) {// has type 1
                    $planIds = array_column($edges, 'plan_id');
                    $plans = $this
                    ->go1
                    ->executeQuery('SELECT * FROM gc_plan WHERE id IN (?)', [$planIds], [DB::INTEGERS])
                    ->fetchAll(DB::OBJ);

                    $plan1 = $plans[0];
                    $date1 = new DateTime($plan1->due_date);

                    $plan2 = $plans[1];
                    $date2 = new DateTime($plan2->due_date);

                    $removedPlan = null;
                    $updatedPlanId = 0;
                    $dueDate = '';

                    $interval = $date1->diff($date2);
                    if ($interval->invert) { // date1 > date2
                        if ($plan1->type == PlanTypes::ASSIGN) {
                            $removedPlan = $plan2;
                        } else {
                            $removedPlan = $plan1;
                            $updatedPlanId = $plan2->id;
                            $dueDate = $plan1->due_date;
                        }
                    } else {
                        if ($plan1->type == PlanTypes::ASSIGN) {
                            $plan1->due_date = $plan2->due_date;
                            $removedPlan = $plan2;
                            $updatedPlanId = $plan1->id;
                            $dueDate = $plan2->due_date;
                        } else {
                            $removedPlan = $plan1;
                        }
                    }

                    // delete type3
                    $removedPlan = Plan::create($removedPlan);
                    $this->go1->delete('gc_plan', ['id' => $removedPlan->id]);
                    $this->go1->insert('gc_plan_revision', [
                        'plan_id' => $removedPlan->id,
                        'type' => $removedPlan->type,
                        'user_id' => $removedPlan->userId,
                        'assigner_id' => $removedPlan->assignerId,
                        'instance_id' => $removedPlan->instanceId,
                        'entity_type' => $removedPlan->entityType,
                        'entity_id' => $removedPlan->entityId,
                        'status' => $removedPlan->status,
                        'created_date' => ($removedPlan->created
                            ?? new Go1DateTime())->setTimeZone(new DateTimeZone("UTC"))->format(Constants::DATE_MYSQL),
                        'due_date' => $removedPlan->due
                            ? $removedPlan->due->setTimeZone(new DateTimeZone("UTC"))->format(Constants::DATE_MYSQL) : null,
                        'data' => $removedPlan->data
                            ? json_encode($removedPlan->data) : null,
                    ]);

                    // delete link type3
                    $this->go1->delete('gc_enrolment_plans', [
                        'enrolment_id' => $plan->enrolment_id,
                        'plan_id' => $removedPlan->id
                    ]);

                    // update type1 due date if not latest
                    if ($updatedPlanId && $dueDate) {
                        $this->go1->update('gc_plan', ['due_date' => $dueDate], ['id' => $updatedPlanId]);
                    }
                }
            }
        }
    }
}
