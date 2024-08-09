<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\core\customer\services\user_explore\handler\UnEnrollUsersHandler;
use go1\domain_users\clients\user_management\lib\Api\SearchApi;
use go1\domain_users\clients\user_management\lib\Model\AccountUserDto;
use go1\domain_users\clients\user_management\lib\Model\BoolFilter;
use go1\domain_users\clients\user_management\lib\Model\IDFilter;
use go1\domain_users\clients\user_management\lib\Model\SearchRequestDto;
use go1\domain_users\clients\user_management\lib\Model\SearchRequestDtoWhere;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\exceptions\ErrorWithErrorCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use go1\util\AccessChecker;
use go1\util\Error;
use Util\Elasticsearch8\ElasticsearchDSL\Sort\FieldSort;

class NonEnrolledController
{
    private const DEFAULT_LIMIT           = 10;
    private const MAPPING                 = ['name', 'given_name', 'family_name'];

    private AccessChecker $accessChecker;
    private LoggerInterface $logger;
    private SearchApi $searchApi;
    private EnrolmentRepository $repository;

    public function __construct(
        AccessChecker $accessChecker,
        LoggerInterface $logger,
        SearchApi $searchApi,
        EnrolmentRepository $repository
    ) {
        $this->accessChecker = $accessChecker;
        $this->logger = $logger;
        $this->searchApi = $searchApi;
        $this->repository = $repository;
    }

    public function get(int $loId, Request $req): JsonResponse
    {
        $portal = $this->accessChecker->contextPortal($req);
        if (!$portal) {
            return Error::createMissingOrInvalidJWT();
        }
        $portalId = $portal->id;

        if (!$this->access($portalId, $req)) {
            $errorData = [
                'message' => 'Learning object is not in your portal or you are not a manager.',
                'error_code' => ErrorMessageCodes::ENROLLMENT_LO_ACCESS_DENIED
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, Error::FORBIDDEN);
        }

        $offset   = $req->query->get('offset', 0);
        $limit    = $req->query->get('limit', 20);
        $sort     = $req->query->get('sort', []);
        $keyword  = strip_tags(urldecode($req->query->get('keyword', '')));

        try {
            $assertion = Assert::lazy();
            $assertion
                ->that($limit, 'limit')->nullOr()->numeric()->min(1)->max(100)
                ->that($offset, 'offset')->nullOr()->numeric()->min(0)
                ->that($sort, 'sort')->nullOr()->isArray()->all()->isArray()
                ->that($loId, 'loId')->numeric()
                ->that($keyword, 'keyword')->nullOr()->string();

            if (!empty($sort)) {
                foreach ($sort as $delta => $value) {
                    $assertion->that($value['field'] ?? null, "sort.$delta")->inArray(self::MAPPING);
                    $assertion->that($value['direction'] ?? null, "sort.$delta")->nullOr()->inArray([
                        FieldSort::ASC,
                        FieldSort::DESC
                    ]);
                }
            }

            $assertion->verifyNow();

            return new JsonResponse($this->loadData($req, $portalId, $loId, $sort, $offset, $limit, $keyword));
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (ErrorWithErrorCode $e) {
            return ErrorMessageCodes::createError($e);
        } catch (Exception $e) {
            $this->logger->error("Failed to query non enrolled users", [
                'exception' => $e
            ]);
            return Error::jr500('Internal server error');
        }
    }

    private function access(int $portalId, Request $req)
    {
        if ($this->accessChecker->isPortalAdmin($req, $portalId)) {
            return true;
        }

        if ($this->accessChecker->isPortalManager($req, $portalId)) {
            return true;
        }

        return false;
    }

    private function loadData(
        Request $req,
        int     $portalId,
        int     $loId,
        array   $sort,
        int     $offset,
        int     $limit,
        string  $keyword
    ): array {
        $user = $this->accessChecker->validUser($req);
        $isAdmin     = $this->accessChecker->isPortalAdmin($req, $portalId);
        $actorUserId = $isAdmin ? 0 : $user->id;

        if (empty($sort) && self::DEFAULT_LIMIT <= $limit) {
            $sort[] = [
                'field' => 'given_name',
                'direction' => 'ASC'
            ];

            $sort[] = [
                'field' => 'family_name',
                'direction' => 'ASC'
            ];
        }

        $queryDataOptions = [
            'portal_id'  => $portalId,
            'lo_id'      => $loId,
            'manager_id' => $actorUserId,
            'sort'       => $sort,
            'offset'     => $offset,
            'limit'      => $limit,
            'is_admin'   => $isAdmin,
            'keyword'    => $keyword
        ];

        $userIds = $this->repository->getLoEnrolledAssignedUserIds($loId, $portalId);
        [$total, $rawUserData] = $this->getUsers($this->accessChecker->jwt($req), $queryDataOptions, $userIds);

        return [
            'data' => $this->formatResult($rawUserData),
            'meta' => [
                'request_id'  => $req->headers->get('x-request-id'),
                'code'        => 'success',
                'filter_info' => [
                    'offset'      => $offset,
                    'limit'       => $limit,
                    'next_offset' => (int)($total > $limit + $offset) ? $limit + $offset : $total,
                    'total'       => $total
                ],
            ]
        ];
    }

    /**
     * @throws Exception
     */
    private function getUsers(string $actorJwt, array $queryOptions, array $userIds): ?array
    {
        try {
            $sort          = $queryOptions['sort'];
            $offset        = $queryOptions['offset'];
            $limit         = $queryOptions['limit'];
            $keyword       = $queryOptions['keyword'];

            $where = new SearchRequestDtoWhere([
                'status' => new BoolFilter([
                    'equals' => true
                ])
            ]);
            if (!empty($userIds)) {
                $where->setGcUserId(new IDFilter([
                    'not_in' => $userIds,
                ]));
            }

            $orderBy = [];
            foreach ($sort as $value) {
                $orderBy[][$value['field']] = $value['direction'] ?? 'DESC';
            }

            $query = new SearchRequestDto([
                'where'        => $where,
                'offset'       => $offset,
                'limit'        => $limit,
                'order_by'     => $orderBy
            ]);

            if (!empty($keyword)) {
                $query->setMultiSearch((object)[
                    'search_term' => $keyword
                ]);
            }

            $this->searchApi->getConfig()->setAccessToken($actorJwt);
            $res = $this->searchApi->searchAccounts($query);
            $accounts = array_map(fn ($accountUserDto) => $this->formatAccount($accountUserDto), $res->getData());

            return [$res->getTotal(), $accounts];
        } catch (Exception $e) {
            $this->logger->error('[#enrolment] Can not get un-enroll & un-assign users.', ['message' => $e->getMessage()]);
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_UN_ENROLLED_USER_NOT_FOUND,
                new HttpException(500, 'Can not get un-enroll & un-assign users.')
            );
        }
    }

    private function formatResult(array $items): array
    {
        if (!$items) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $data = isset($item->data) ? (is_scalar($item->data) ? json_decode($item->data, true) : $item->data) : null;

            $result[] = [
                'id'         => $item->id,
                'user_id'    => $item->user_id,
                'mail'       => $item->mail,
                'first_name' => $item->first_name,
                'last_name'  => $item->last_name,
                'full_name'  => $item->first_name . ' ' . $item->last_name,
                'avatar'     => $data['avatar']['uri'] ?? null
            ];
        }
        return $result;
    }

    private function formatAccount(AccountUserDto $accountUserDto): object
    {
        return (object)[
            'id'         => $accountUserDto->getGcUserAccountId(),
            'user_id'    => $accountUserDto->getUser()->getGcUserId(),
            'mail'       => $accountUserDto->getUser()->getEmail(),
            'first_name' => $accountUserDto->getUser()->getGivenName(),
            'last_name'  => $accountUserDto->getUser()->getFamilyName(),
            'data'       => [
                'avatar' => [
                    'uri' => $accountUserDto->getUser()->getPicture()
                ]
            ]
        ];
    }
}
