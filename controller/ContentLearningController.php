<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Marshaller;
use go1\core\util\client\federation_api\v1\schema\input\PortalFilter;
use go1\core\util\client\federation_api\v1\schema\object\LearningPlan;
use go1\core\util\client\federation_api\v1\schema\query\getLearningPlans;
use go1\enrolment\content_learning\ContentLearningFilterOptions;
use go1\enrolment\content_learning\ContentLearningQuery;
use go1\enrolment\content_learning\ContentLearningQueryResult;
use go1\enrolment\content_learning\DateTimeFilter;
use go1\enrolment\services\PortalService;
use go1\enrolment\services\ReportDataService;
use go1\enrolment\services\UserService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningController
{
    public const ACTIVITY_TYPE_ASSIGNED = 'assigned';
    public const ACTIVITY_TYPE_SELF_DIRECTED = 'self-directed';

    private AccessChecker $accessChecker;
    private GraphQLClient $graphQLClient;
    private Marshaller $marshaller;
    private LoggerInterface $logger;
    private ContentLearningQuery $contentLearningQuery;
    private array $supportFields = [
        'legacyId',
        'state.legacyId',
        'user.email',
        'user.legacyId',
    ];
    private PortalService $portalService;
    private ReportDataService $reportDataService;
    private bool $isContentLearningUserMigration;

    public function __construct(
        AccessChecker $accessChecker,
        GraphQLClient $graphQLClient,
        Marshaller $marshaller,
        LoggerInterface $logger,
        ContentLearningQuery $contentLearningQuery,
        PortalService $portalService,
        ReportDataService $reportDataService,
        bool $isContentLearningUserMigration
    ) {
        $this->accessChecker = $accessChecker;
        $this->graphQLClient = $graphQLClient;
        $this->marshaller = $marshaller;
        $this->logger = $logger;
        $this->contentLearningQuery = $contentLearningQuery;
        $this->portalService = $portalService;
        $this->reportDataService = $reportDataService;
        $this->isContentLearningUserMigration = $isContentLearningUserMigration;
    }

    public function get(int $portalId, int $loId, Request $req): JsonResponse
    {
        $isAccountsAdmin = $this->accessChecker->isAccountsAdmin($req);

        $portal = null;
        $isManager = null;
        $account = null;
        $user = null;
        if (!$isAccountsAdmin) {
            if (!$user = $this->accessChecker->validUser($req)) {
                return Error::createMissingOrInvalidJWT();
            }

            if (!$account = $this->accessChecker->validAccount($req, $portalId)) {
                return Error::jr('Invalid JWT');
            }

            $isContentAdmin = $this->accessChecker->isContentAdministrator($req, $portalId);
            $isManager = $this->accessChecker->isPortalManager($req, $portalId, false) && !$isContentAdmin;
            $portal = $this->accessChecker->contextPortal($req);

            if ((!$isContentAdmin && !$isManager) || empty($portal)) {
                return Error::createMissingOrInvalidJWT();
            }
        }

        empty($portal) && $portal = $this->portalService->loadBasicById($portalId);

        $o = new ContentLearningFilterOptions();
        $o->loId = $loId;
        $o->status = $req->query->get('status');
        $o->activityType = $req->query->get('activityType');
        $o->userIds = $req->query->get('userIds', null);
        $o->offset = (int) $req->query->get('offset', 0);
        $o->limit = (int) $req->query->get('limit', 20);
        $o->passed = $req->query->get('passed');
        $o->sort = $req->query->get('sort', ['updatedAt' => 'asc']);
        $o->facet = $req->query->get('facet', false);
        $o->overdue = $req->query->get('overdue');
        $o->assignerIds = $req->query->get('assignerIds');
        $includeInactive = filter_var($req->query->get('includeInactivePortalAccounts', false), FILTER_VALIDATE_BOOLEAN);
        if (!$includeInactive) {
            $o->accountStatus = 1;
        }
        $o->groupId = (int) $req->query->get('groupId', 0);
        $startedAt = $req->query->get('startedAt');
        $endedAt = $req->query->get('endedAt');
        $assignedAt = $req->query->get('assignedAt');
        $dueAt = $req->query->get('dueAt');
        $fields = $req->query->get('fields');

        if (!$isAccountsAdmin && $isManager) {
            $o->managerAccount = $account;
        }

        $o->portal = $portal;

        try {
            $claim = Assert::lazy();
            $claim
                ->that($o->status, 'status')->nullOr()->string()->inArray(UserLearningController::STATUSES)
                ->that($o->activityType, 'activityType')->nullOr()->string()->inArray([self::ACTIVITY_TYPE_ASSIGNED, self::ACTIVITY_TYPE_SELF_DIRECTED])
                ->that($o->userIds, 'userIds')->nullOr()->isArray()->all()->integerish()->min(1)
                ->that($o->userIds, 'userIds')->nullOr()->isArray()->maxCount(20)
                ->that($o->assignerIds, 'assignerIds')->nullOr()->isArray()->all()->integerish()->min(1)
                ->that($o->assignerIds, 'assignerIds')->nullOr()->isArray()->maxCount(20)
                ->that($o->offset, 'offset')->integerish()->min(0)
                ->that($o->limit, 'limit')->integerish()->max(100)
                ->that($o->passed, 'passed')->nullOr()->integerish()->inArray([0, 1])
                ->that($o->sort, 'sort')->isArray()->all()->string()->inArray(['desc', 'asc'])
                ->that($o->facet, 'facet')->nullOr()->boolean()
                ->that($o->overdue, 'overdue')->nullOr()->boolean()
                ->that($o->sort ? array_keys($o->sort) : null, 'sort')->nullOr()->isArray()->all()->inArray(['startedAt', 'endedAt', 'updatedAt'])
                ->that($o->groupId, 'groupId')->integerish()->min(0)
                ->that($this->isValidDateTimeFilter('startedAt', $startedAt))->true()
                ->that($this->isValidDateTimeFilter('endedAt', $endedAt))->true()
                ->that($this->isValidDateTimeFilter('assignedAt', $assignedAt))->true()
                ->that($this->isValidDateTimeFilter('dueAt', $dueAt))->true()
                ->that($fields, 'fields')->nullOr()->string()->satisfy(
                    function ($fields) use ($o, $claim) {
                        $o->fields = explode(',', $fields);
                        foreach ($o->fields as $field) {
                            $claim->that($field, 'fields')->inArray($this->supportFields);
                        }
                    }
                );
            $claim->verifyNow();

            if (isset($o->passed) && (!isset($o->status) || $o->status != EnrolmentStatuses::COMPLETED)) {
                return Error::simpleErrorJsonResponse('We need both the pass and completed enrolment statuses when filtering Passed enrolments');
            }

            $o->startedAt = is_null($startedAt) ? null : DateTimeFilter::create($startedAt);
            $o->endedAt = is_null($endedAt) ? null : DateTimeFilter::create($endedAt);
            $o->assignedAt = is_null($assignedAt) ? null : DateTimeFilter::create($assignedAt);
            $o->dueAt = is_null($dueAt) ? null : DateTimeFilter::create($dueAt);

            if (!empty($o->userIds)) {
                $o->userIds = array_map(fn ($id) => (int) $id, $o->userIds);
            }
            if ($this->isContentLearningUserMigration) {
                $facets = $this->getFacetFromES($o, $portalId, $loId, $this->accessChecker->jwt($req));
            } else {
                $facets = null;
            }
            $learning = $this->doGet($o, $facets);
            if ($o->facet) {
                if (!$this->isContentLearningUserMigration) {
                    $facets = $this->getFacet($o);
                }
                $learning['data']['facet'] = $facets;
            }

            return new JsonResponse($learning);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Errors on content learning', [
                'exception' => $e,
            ]);
            return Error::jr500('Internal error');
        }
    }

    private function isValidDateTimeFilter(string $inputName, $inputValue): bool
    {
        if (is_null($inputValue)) {
            return true;
        }

        $lazy = Assert::lazy();

        if (is_scalar($inputValue)) {
            $lazy
                ->that($inputValue, $inputName)->integerish()
                ->verifyNow();
        } else {
            $lazy
                ->that($inputValue, $inputName)->isArray()
                ->verifyNow();

            $lazy
                ->that(array_keys($inputValue), $inputName)->all()->inArray(['from', 'to'])
                ->that($inputValue['from'] ?? null, "$inputName.from")->nullOr()->integerish()
                ->that($inputValue['to'] ?? null, "$inputName.to")->nullOr()->integerish()
                ->verifyNow();

            if (!empty($inputValue['from']) && !empty($inputValue['to'])) {
                $lazy
                    ->that($inputValue['from'] <= $inputValue['to'], "$inputName")->true()
                    ->verifyNow();
            }
        }

        return true;
    }

    private function getFacetFromES(ContentLearningFilterOptions $o, int $portalId, int $loId, string $jwt): array
    {
        $filters = $this->convertToEsFilterFormat($o);
        $result = $this->reportDataService->getReportCounts($portalId, $loId, $jwt, $filters);
        $summary = $result['summary_counts'];
        if (empty($o->activityType)) {
            return [
                'all'                            => $summary['all'],
                'overdue'                        => $summary['overdue'],
                'assigned'                       => $summary['assigned'],
                'self-directed'                  => $summary['self_directed'],
                EnrolmentStatuses::NOT_STARTED   => $summary['not_started'],
                EnrolmentStatuses::IN_PROGRESS   => $summary['in_progress'],
                EnrolmentStatuses::COMPLETED     => $summary['completed'],
                ContentLearningQuery::NOT_PASSED => $summary['not_passed'],
            ];
        } else {
            return [
                'total'                          => $summary['all'],
                EnrolmentStatuses::NOT_STARTED   => $summary['not_started'],
                EnrolmentStatuses::IN_PROGRESS   => $summary['in_progress'],
                EnrolmentStatuses::COMPLETED     => $summary['completed'],
                ContentLearningQuery::NOT_PASSED => $summary['not_passed'],
                'overdue'                        => $summary['overdue'],
            ];
        }
    }

    private function convertToEsFilterFormat(ContentLearningFilterOptions $o): array
    {
        $filters = [];
        if ($o->userIds) {
            $userIds = array_map(fn ($id) => (int) $id, $o->userIds);
            $filters['user_ids'] = $userIds;
        }
        if ($o->status) {
            $filters['status'] = $o->status;
        }
        if ($o->activityType) {
            $filters['activity_type'] = $o->activityType;
        }
        if ($o->passed !== null) {
            $filters['passed'] = (bool) $o->passed;
        }
        if ($o->overdue !== null) {
            $filters['overdue'] = (bool) $o->overdue;
        }
        if ($o->assignerIds) {
            $assignerIds = array_map(fn ($id) => (int) $id, $o->assignerIds);
            $filters['assigner_user_ids'] = $assignerIds;
        }
        if ($o->groupId) {
            $filters['assigned_group_id'] = $o->groupId;
        }
        if ($o->accountStatus !== null) {
            $filters['account_status'] = (bool) $o->accountStatus;
        }
        if ($o->startedAt) {
            $filters['start_time'] = $this->convertDateTimeFilterToESFormat($o->startedAt);
        }
        if ($o->endedAt) {
            $filters['end_time'] = $this->convertDateTimeFilterToESFormat($o->endedAt);
        }
        if ($o->assignedAt) {
            $filters['assigned_time'] = $this->convertDateTimeFilterToESFormat($o->assignedAt);
        }
        if ($o->dueAt) {
            $filters['due_time'] = $this->convertDateTimeFilterToESFormat($o->dueAt);
        }
        return $filters;
    }

    private function convertDateTimeFilterToEsFormat(?DateTimeFilter $dateTimeFilter): array
    {
        if ($dateTimeFilter) {
            $filters = [];
            if (property_exists($dateTimeFilter, 'from') && $dateTimeFilter->from) {
                $filters['from'] = $dateTimeFilter->from->getTimestamp();
            }
            if (property_exists($dateTimeFilter, 'to') && $dateTimeFilter->to) {
                $filters['to'] = $dateTimeFilter->to->getTimestamp();
            }
            return $filters;
        }
        return [];
    }

    private function getFacet(ContentLearningFilterOptions $o): array
    {
        # All learner facet
        if (empty($o->activityType)) {
            return $this->contentLearningQuery->getFacet($o);
        }

        switch ($o->activityType) {
            case self::ACTIVITY_TYPE_SELF_DIRECTED:
                $result = $this->contentLearningQuery->findPlansAndLearningRecords($o, UserService::USER_MANAGEMENT_PAGE_SIZE + 1);
                $countResult = $result->getFacetCount();
                break;

            case self::ACTIVITY_TYPE_ASSIGNED:
                $result = $this->contentLearningQuery->findPlans($o, UserService::USER_MANAGEMENT_PAGE_SIZE + 1);
                $countResult = $result->getFacetCount();
                $overDueOption = clone $o;
                $overDueOption->overdue = true;
                $overdue = $this->contentLearningQuery->findPlans($overDueOption, UserService::USER_MANAGEMENT_PAGE_SIZE + 1)->getCount();
                break;

            default:
                throw new Exception('Unknown activityType');
        }

        $total = 0;
        $facet = [];
        foreach ($countResult as $result) {
            if ($result->status == EnrolmentStatuses::COMPLETED && $result->pass == EnrolmentStatuses::FAILED) {
                $facet[ContentLearningQuery::NOT_PASSED] = (int) ($result->total);
            }
            $enrolmentStatus = $result->status ?? EnrolmentStatuses::NOT_STARTED;
            $facet[$enrolmentStatus] = ($facet[$enrolmentStatus] ?? 0) + (int) ($result->total);
            $total += $result->total;
        }

        return [
            'total'                          => $total,
            EnrolmentStatuses::NOT_STARTED   => $facet[EnrolmentStatuses::NOT_STARTED] ?? 0,
            EnrolmentStatuses::IN_PROGRESS   => $facet[EnrolmentStatuses::IN_PROGRESS] ?? 0,
            EnrolmentStatuses::COMPLETED     => $facet[EnrolmentStatuses::COMPLETED] ?? 0,
            ContentLearningQuery::NOT_PASSED => $facet[ContentLearningQuery::NOT_PASSED] ?? 0,
            'overdue'                        => $overdue ?? 0,
        ];
    }

    private function doGet(ContentLearningFilterOptions $o, ?array $facets): array
    {
        # Query against gc_enrolment table only
        if (!empty($o->status) && empty($o->activityType) && $o->status != EnrolmentStatuses::NOT_STARTED) {
            /**
             * Currently, the data-layer does not support for filter active users
             * Need to avoid calling directly to data-layer
             *
             * @link https://go1web.atlassian.net/browse/MGL-784
             */
            $learningRecordsResult = $this->contentLearningQuery->findLearningRecords($o, $facets['all'] ?? $facets['total'] ?? (UserService::USER_MANAGEMENT_PAGE_SIZE + 1));

            return $this->loadMultipleByIds($learningRecordsResult, $o, $facets);
        }

        # Query against gc_plan table only
        # This can be filtered by enrolment status and active users
        if ($o->overdue || (!empty($o->activityType) && self::ACTIVITY_TYPE_ASSIGNED == $o->activityType)) {
            $result = $this->contentLearningQuery->findPlans($o, $facets['all'] ?? $facets['total'] ?? (UserService::USER_MANAGEMENT_PAGE_SIZE + 1));

            return $this->loadMultipleByIds($result, $o, $facets);
        }

        # Query against union between gc_plan & gc_enrolment
        # This can be filtered by enrolment status and active users
        return $this->loadMultipleByIds($this->contentLearningQuery->findPlansAndLearningRecords($o, $facets['all'] ?? $facets['total'] ?? (UserService::USER_MANAGEMENT_PAGE_SIZE + 1)), $o, $facets);
    }

    private function loadMultipleByIds(ContentLearningQueryResult $result, ContentLearningFilterOptions $o, ?array $facets): array
    {
        $edges = [];
        $items = $result->getItems();
        if (!empty($items)) {
            $ids = [];
            foreach ($items as $item) {
                if (empty($item->enrolmentId)) {
                    $ids[] = base64_encode("go1:LearningPlan:gc_plan.$item->planId");
                } else {
                    $ids[] = base64_encode("go1:LearningPlan:gc_enrolment.$item->enrolmentId");
                }
            }

            $this->logger->debug(
                'Debug output for getLearningPlans: ',
                [
                    'portal_title' => $o->portal->title,
                    'ids'          => $ids,
                    'items'        => $items,
                ]
            );

            $query = new getLearningPlans();
            if ($o->fields) {
                $selectedFields = $query::fields();
                foreach ($o->fields as $field) {
                    switch ($field) {
                        case 'legacyId':
                            $selectedFields->withLegacyId();
                            break;
                        case 'state.legacyId':
                            $selectedFields->withState($query::fields()::state()->withLegacyId());
                            break;
                        case 'user.email':
                            $selectedFields->withUser($query::fields()::user()->withEmail());
                            break;
                        case 'user.legacyId':
                            $selectedFields->withUser($query::fields()::user()->withLegacyId());
                            break;
                    }
                }
            } else {
                $selectedFields = $query::fields()
                    ->withLegacyId()
                    ->withDueDate()
                    ->withCreatedAt()
                    ->withUpdatedAt()
                    ->withState(
                        $query::fields()::state()
                          ->withLegacyId()
                          ->withStatus()
                          ->withPassed()
                          ->withStartedAt()
                          ->withEndedAt()
                          ->withUpdatedAt()
                    )
                    ->withUser(
                        $query::fields()::user()
                         ->withLegacyId()
                         ->withFirstName()
                         ->withLastName()
                         ->withEmail()
                         ->withAvatarUri()
                         ->withStatus()
                         ->withAccount(
                             $query::fields()::user()::account()
                             ->withArguments(
                                 $query::fields()::user()::account()::arguments()
                                   ->withPortal(
                                       PortalFilter::create()->name($o->portal->title)
                                   )
                             )
                             ->withFields(
                                 $query::fields()::user()::account()::fields()
                                ->withLegacyId()
                                ->withStatus()
                             )
                         )
                    )
                    ->withAuthor(
                        $query::fields()::author()
                       ->withLegacyId()
                       ->withFirstName()
                       ->withLastName()
                       ->withEmail()
                       ->withAvatarUri()
                       ->withStatus()
                    );
            }
            $query
                ->withArguments($query::arguments()->withIds($ids))
                ->withFields($selectedFields);

            $learningPlans = $query->execute($this->graphQLClient, $this->marshaller);
            if ($learningPlans) {
                foreach ($learningPlans as $learningPlan) {
                    $edges[] = $o->fields ? $this->formatFields($learningPlan, $o->fields) : $this->format($learningPlan);
                }
            }
        }

        if ($facets) {
            $totalCount = $facets['total'] ?? $facets['all'];
        } else {
            $totalCount = $result->getCount();
        }

        return [
            'data' => [
                'totalCount' => $totalCount,
                'pageInfo'   => [
                    'hasNextPage' => ($o->offset + $o->limit) < $totalCount,
                ],
                'edges'      => $edges,
            ],
        ];
    }

    private function format(LearningPlan $learningPlan): array
    {
        $enrolmentId = !empty($learningPlan->state) ? $learningPlan->state->legacyId : null;
        $planId = $learningPlan->legacyId;

        if ($planId == $enrolmentId) {
            $plan = [
                'legacyId'  => null,
                'dueDate'   => null,
                'createdAt' => null,
                'updatedAt' => null,
            ];
        } else {
            $plan = [
                'legacyId'  => $learningPlan->legacyId,
                'dueDate'   => !$learningPlan->dueDate ? null : $learningPlan->dueDate->getTimestamp(),
                'createdAt' => !$learningPlan->createdAt ? null : $learningPlan->createdAt->getTimestamp(),
                'updatedAt' => !$learningPlan->updatedAt ? null : $learningPlan->updatedAt->getTimestamp(),
            ];
        }

        $selfDirected = empty($plan['legacyId']); # no plan object

        return [
            'node' => $plan + [
                    'activityType' => !empty($plan['legacyId']) ? ($selfDirected ? self::ACTIVITY_TYPE_SELF_DIRECTED : self::ACTIVITY_TYPE_ASSIGNED) : self::ACTIVITY_TYPE_SELF_DIRECTED,
                    'state'        => !empty($learningPlan->state) ? [
                        'legacyId'  => $learningPlan->state->legacyId,
                        'status'    => UserLearningController::STATUSES[$learningPlan->state->status],
                        'passed'    => $learningPlan->state->passed,
                        'startedAt' => !$learningPlan->state->startedAt ? null : $learningPlan->state->startedAt->getTimestamp(),
                        'endedAt'   => !$learningPlan->state->endedAt ? null : $learningPlan->state->endedAt->getTimestamp(),
                        'updatedAt' => !$learningPlan->state->updatedAt ? null : $learningPlan->state->updatedAt->getTimestamp(),
                    ] : null,
                    'user'         => [
                        'legacyId'  => $learningPlan->user->legacyId,
                        'firstName' => $learningPlan->user->firstName,
                        'lastName'  => $learningPlan->user->lastName,
                        'email'     => $learningPlan->user->email,
                        'avatarUri' => $learningPlan->user->avatarUri,
                        'status'    => ($learningPlan->user->status == 'ACTIVE') ? true : false,
                        'account'   => !empty($learningPlan->user->account)
                            ? [
                                'legacyId' => $learningPlan->user->account->legacyId,
                                'status'   => ($learningPlan->user->account->status == 'ACTIVE') ? true : false,
                            ]
                            : null,
                    ],
                    'author'       => !empty($learningPlan->author)
                        ? [
                            'legacyId'  => $learningPlan->author->legacyId,
                            'firstName' => $learningPlan->author->firstName,
                            'lastName'  => $learningPlan->author->lastName,
                            'email'     => $learningPlan->author->email,
                            'avatarUri' => $learningPlan->author->avatarUri,
                            'status'    => ($learningPlan->author->status == 'ACTIVE') ? true : false,
                        ] : null,
                ],
        ];
    }

    private function formatFields(LearningPlan $learningPlan, array $fields): array
    {
        foreach ($fields as $field) {
            switch ($field) {
                case 'legacyId':
                    $node['legacyId'] = $learningPlan->legacyId;
                    break;
                case 'state.legacyId':
                    $node['state'] = !empty($learningPlan->state) ? [
                        'legacyId' => $learningPlan->state->legacyId,
                    ] : null;
                    break;

                case 'user.email':
                    $node['user'] = !empty($learningPlan->user) ? [
                        'email' => $learningPlan->user->email,
                    ] : null;
                    break;

                case 'user.legacyId':
                    $node['user'] = !empty($learningPlan->user) ? [
                        'legacyId' => $learningPlan->user->legacyId,
                    ] : null;
                    break;
            }
        }

        return [
            'node' => $node ?? [],
        ];
    }
}
