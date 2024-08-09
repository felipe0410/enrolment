<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use go1\core\util\client\federation_api\v1\gql;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Marshaller;
use go1\core\util\client\federation_api\v1\schema\input\IntFilterInput;
use go1\core\util\client\federation_api\v1\schema\input\LearningStateValueFilter;
use go1\core\util\client\federation_api\v1\schema\object\LearningPlanEdge;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Exception;

class UserLearningController
{
    private AccessChecker   $accessChecker;
    private GraphQLClient   $graphQLClient;
    private Marshaller      $marshaller;
    private LoggerInterface $logger;
    public const STATUSES = [
        'NOT_STARTED' => EnrolmentStatuses::NOT_STARTED,
        'IN_PROGRESS' => EnrolmentStatuses::IN_PROGRESS,
        'PENDING'     => EnrolmentStatuses::PENDING,
        'COMPLETED'   => EnrolmentStatuses::COMPLETED,
        'EXPIRED'     => EnrolmentStatuses::EXPIRED,
    ];

    public function __construct(
        AccessChecker $accessChecker,
        GraphQLClient $graphQLClient,
        Marshaller $marshaller,
        LoggerInterface $logger
    ) {
        $this->accessChecker = $accessChecker;
        $this->graphQLClient = $graphQLClient;
        $this->marshaller = $marshaller;
        $this->logger = $logger;
    }

    public function get(int $portalId, Request $req): JsonResponse
    {
        if (!$user = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        $userId = $req->query->get('userId', $user->id);

        $contentId = $req->query->get('contentId', null);
        $contentId = $contentId ? (int) $contentId : $contentId;
        $status = $req->query->get('status');
        $offset = (int) $req->query->get('offset', 0);
        $limit = (int) $req->query->get('limit', 20);
        $sort = $req->query->get('sort', []);

        if (!$account = $this->accessChecker->validAccount($req, $portalId)) {
            return Error::createMissingOrInvalidJWT();
        }

        if ($userId !== $user->id) {
            if (!$this->accessChecker->isPortalAdmin($req, $portalId)) {
                return Error::jr403('Access denied.');
            }
        }

        try {
            Assert::lazy()
                ->that($status, 'status')->nullOr()->string()->inArray(self::STATUSES)
                ->that($contentId, 'contentId')->nullOr()->integer()->min(1)
                ->that($offset, 'offset')->integer()->min(0)
                ->that($limit, 'limit')->integer()->max(100)
                ->that($sort, 'sort')->isArray()->all()->string()->inArray(['desc', 'asc'])
                ->that($sort ? array_keys($sort) : null, 'sort')->nullOr()->isArray()->all()->inArray(['startedAt', 'endedAt', 'updatedAt'])
                ->verifyNow();

            $learning = $this->doGet($portalId, $userId, $status, $contentId, $sort, $offset, $limit);

            return new JsonResponse($learning);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Errors on user learning', [
                'exception' => $e,
            ]);

            return Error::jr500('Internal error');
        }
    }

    private function doGet(
        int $portalId,
        int $userId,
        string $status = null,
        int $contentId = null,
        array $sortInput = [],
        int $offset = 0,
        int $limit = 20
    ): array {
        $findLearningPlans = gql::findLearningPlans();
        $findLearningPlans->withFields(
            $findLearningPlans::fields()
            ->withTotalCount()
            ->withPageInfo(
                $findLearningPlans::fields()::pageInfo()
                ->withHasNextPage()
            )
            ->withEdges(
                $findLearningPlans::fields()::edges()
                ->withNode(
                    $findLearningPlans::fields()::edges()::node()
                    ->withState(
                        $findLearningPlans::fields()::edges()::node()::state()
                        ->withLegacyId()
                        ->withStatus()
                        ->withStartedAt()
                        ->withEndedAt()
                        ->withUpdatedAt()
                    )
                    ->withLo(
                        $findLearningPlans::fields()::edges()::node()::lo()
                        ->withLegacyId()
                        ->withTitle()
                        ->withLabel()
                        ->withImage()
                        ->withPublisher(
                            $findLearningPlans::fields()::edges()::node()::lo()::publisher()
                            ->withSubDomain()
                        )
                    )
                )
            )
        );
        $filters = $findLearningPlans::arguments()::filters();
        $filters->withUserLegacyId(gql::filters()::int()->eq($userId));
        $filters->withSpaceId(gql::filters()::string()->eq(base64_encode("go1:Space:Portal.$portalId")));
        if (!empty($status)) {
            $filters->withStatus(LearningStateValueFilter::create()->oneOf([array_search($status, self::STATUSES)]));
        }

        if (!empty($contentId)) {
            $filters->withLoLegacyId(IntFilterInput::create()->eq($contentId));
        }

        $sort = $findLearningPlans::arguments()::orderBy()->updatedAt('DESC');
        if (!empty($sortInput)) {
            $sort = $findLearningPlans::arguments()::orderBy();
            foreach ($sortInput as $key => $value) {
                $sort->{$key}(strtoupper($value));
            }
        }

        $findLearningPlans->withArguments(
            $findLearningPlans::arguments()
            ->withFilters($filters)
            ->withFirst($limit)
            ->withAfter(base64_encode("go1:Offset:$offset"))
            ->withOrderBy($sort)
        );

        $result = $findLearningPlans->execute($this->graphQLClient, $this->marshaller);

        return [
            'data' => [
                'totalCount' => $result->totalCount,
                'pageInfo'   => [
                    'hasNextPage' => $result->pageInfo->hasNextPage,
                ],
                'edges'      => array_map(fn (LearningPlanEdge $learningPlan) => $this->format($learningPlan), $result->edges)
            ]
        ];
    }

    private function format(LearningPlanEdge $learningPlanEdge): array
    {
        return [
            'node' => [
                'state' => [
                    'legacyId'  => $learningPlanEdge->node->state->legacyId,
                    'status'    => self::STATUSES[$learningPlanEdge->node->state->status],
                    'startedAt' => !$learningPlanEdge->node->state->startedAt ? null : $learningPlanEdge->node->state->startedAt->getTimestamp(),
                    'endedAt'   => !$learningPlanEdge->node->state->endedAt ? null : $learningPlanEdge->node->state->endedAt->getTimestamp(),
                    'updatedAt' => !$learningPlanEdge->node->state->updatedAt ? null : $learningPlanEdge->node->state->updatedAt->getTimestamp(),
                ],
                'lo'    => [
                    'id'        => $learningPlanEdge->node->lo->legacyId,
                    'title'     => $learningPlanEdge->node->lo->title,
                    'label'     => $learningPlanEdge->node->lo->label,
                    'image'     => $learningPlanEdge->node->lo->image,
                    'publisher' => [
                        'subDomain' => $learningPlanEdge->node->lo->publisher->subDomain,
                    ],
                ]
            ]
        ];
    }
}
