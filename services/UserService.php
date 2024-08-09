<?php

namespace go1\enrolment\services;

use Exception;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use go1\core\util\Roles;
use go1\domain_users\clients\user_management\lib\Api\StaffApi;
use go1\domain_users\clients\user_management\lib\ApiException;
use go1\domain_users\clients\user_management\lib\Model\AccountUserDto;
use go1\domain_users\clients\user_management\lib\Model\BoolFilter;
use go1\domain_users\clients\user_management\lib\Model\ElasticSearchResponseDto;
use go1\domain_users\clients\user_management\lib\Model\ErrorResponse;
use go1\domain_users\clients\user_management\lib\Model\GuidFilter;
use go1\domain_users\clients\user_management\lib\Model\IDFilter;
use go1\domain_users\clients\user_management\lib\Model\SearchRequestDto;
use go1\domain_users\clients\user_management\lib\Model\SearchRequestDtoWhere;
use go1\domain_users\clients\user_management\lib\Model\WhereInputDto;
use go1\util\Service;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use stdClass;

class UserService
{
    private UserDomainHelper $userDomainHelper;
    private array $userCache = [];
    private Client $client;
    private LoggerInterface $logger;
    private string $iamUrl;
    private StaffApi $staffApi;
    public const USER_MANAGEMENT_PAGE_SIZE = 10000;
    private const NUMBER_OF_MANAGER_LEVELS = 4;

    private const ROLE_GUIDS = [
        Roles::ADMIN         => "rol_01G3PZS7NCBKB170TEP3CC2BBH",
        Roles::ADMIN_CONTENT => "rol_01G3PZS7NXC78RP57SG0EXD0Z5",
        Roles::ASSESSOR      => "rol_01G3PZS7NY0579J21R4WB52HKJ",
        Roles::STUDENT       => "rol_01G3PZS7NZ0CF95J340P55FSZH",
        Roles::MANAGER       => "rol_01G3PZS7NZBW04V44QBM48PP0C",
    ];

    public function __construct(
        UserDomainHelper $userDomainHelper,
        Client           $client,
        LoggerInterface  $logger,
        StaffApi         $staffApi
    ) {
        $this->userDomainHelper = $userDomainHelper;
        $this->client = $client;
        $this->logger = $logger;
        $this->iamUrl = Service::url(
            'iam',
            getenv('ENV') ?: 'dev',
            getenv('SERVICE_URL_PATTERN') ?: null
        );
        $this->staffApi = $staffApi;
    }

    // GET /iam/users/{guid} or /iam/accounts/{guid}

    /**
     * @throws GuzzleException
     */
    public function get(string $id, string $type, string $jwt = null): ?stdClass
    {
        if (!in_array($type, array("users", "accounts"))) {
            $this->logger->error("Wrong type: {$type} to query");
            return null;
        }

        // W/A for https://go1web.atlassian.net/browse/PSE-1298
        if ($id === '1') {
            return null;
        }

        // It's required to put JWT in Header for internal service call
        // but somehow it don't work for api gateway, have to put in GET
        try {
            $req = "{$this->iamUrl}/{$type}/{$id}";
            $result = $this->client->get(
                $req,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . (is_null($jwt) ? UserHelper::ROOT_JWT : $jwt)
                    ]
                ]
            );

            return json_decode($result->getBody()->getContents());
        } catch (Exception $e) {
            $this->logger->error('Failed to get user or account', [
                'type' => $type,
                'id' => $id,
                'exception' => $e
            ]);

            throw $e;
        }
    }

    // GET /iam/accounts

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function findAccountWithPortalAndUser(string $portalId, string $userId, string $jwt = null): ?stdClass
    {
        $user = $this->get($userId, 'users', $jwt);
        if (!is_null($user)) {
            try {
                $queryParam = [
                    'user_guid' => $user->user_guid,
                    'portal_id' => $portalId
                ];

                $req = "{$this->iamUrl}/accounts?" . http_build_query($queryParam);
                $result = $this->client->get(
                    $req,
                    [
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer ' . (is_null($jwt) ? UserHelper::ROOT_JWT : $jwt)
                        ]
                    ]
                );

                return json_decode($result->getBody()->getContents());
            } catch (Exception $e) {
                $this->logger->error('Failed to search account', [
                    'portalId' => $portalId,
                    'userId' => $userId,
                    'exception' => $e
                ]);

                throw $e;
            }
        }

        return null;
    }

    public function load(int $userId, string $portalName): ?User
    {
        $cacheId = "cache:$userId:$portalName";
        if (isset($this->userCache[$cacheId])) {
            return $this->userCache[$cacheId];
        }

        $this->userCache[$cacheId] = $this->userDomainHelper->loadUser((string) $userId, $portalName);

        return $this->userCache[$cacheId];
    }

    /**
     * @return AccountUserDto[]
     */
    private function searchAccountsInPortal(int $portalId, SearchRequestDtoWhere $where, int $limit): array
    {
        $searchRequestDto = (new SearchRequestDto())
            ->setWhere($where)
            ->setOffset(0)
            ->setLimit($limit);
        try {
            $this->staffApi->getConfig()->setAccessToken(UserHelper::ROOT_JWT);
            $response = $this->staffApi->staffSearchAccounts($portalId, $searchRequestDto);
        } catch (ApiException $e) {
            $this->logger->error('Failed to search accounts with user management service', [
                'exception' => $e
            ]);
            throw new Exception('Failed to search accounts');
        }

        if ($response instanceof ElasticSearchResponseDto) {
            return $response->getData();
        } elseif ($response instanceof ErrorResponse) {
            $this->logger->error('Failed to search accounts with user management service', [
                'code' => $response->getErrorCode(),
                'message' => $response->getMessage()
            ]);
            throw new Exception('Failed to search accounts');
        } else {
            $this->logger->error('Failed to search accounts with user management service', [
                'response' => $response
            ]);
            throw new Exception('Failed to search accounts');
        }
    }

    /**
     * @param string[] $managerAccountGuids
     * @return AccountUserDto[]
     */
    private function getDirectlyManagedAccounts(int $portalId, array $managerAccountGuids): array
    {
        if (count($managerAccountGuids) === 0) {
            return [];
        }
        $searchRequestDtoWhere = (new SearchRequestDtoWhere())
            ->setRoleGuids(
                (new GuidFilter())
                    ->setEquals(self::ROLE_GUIDS[Roles::MANAGER])
            )
            ->setManagerGuids(
                (new GuidFilter())
                    ->setIn($managerAccountGuids)
            );

        return $this->searchAccountsInPortal($portalId, $searchRequestDtoWhere, self::USER_MANAGEMENT_PAGE_SIZE);
    }

    /**
    * @param ?int[] $userIds
    * @param ?string[] $managerAccountGuids
    * @return array<int,AccountUserDto>
    */
    private function loadAccountsFromUserIds(int $portalId, array $userIds, ?int $managerAccountId, ?array $managerAccountGuids, int $chunkSize): array
    {
        $searchRequestDtoWhere = (new SearchRequestDtoWhere())
            ->setGcUserId((new IDFilter())->setIn($userIds));
        if ($managerAccountId !== null) {
            $searchRequestDtoWhere->setOr(
                [
                    (new WhereInputDto())
                        ->setGcUserAccountId(
                            (new IDFilter())
                                ->setEquals($managerAccountId)
                        ),
                    (new WhereInputDto())
                        ->setManagerGuids(
                            (new GuidFilter())
                                ->setIn($managerAccountGuids)
                        )
                ]
            );
        }
        $accounts = $this->searchAccountsInPortal($portalId, $searchRequestDtoWhere, $chunkSize);
        $accountsByUserId = [];
        foreach ($accounts as $account) {
            $accountsByUserId[(int) $account->getUser()->getGcUserId()] = $account;
        }
        return $accountsByUserId;
    }

    /**
     * @return string[]
     */
    private function getAllManagerAccountGuidsForManager(int $portalId, string $managerAccountGuid): array
    {
        $managerAccountGuidsOfLevel = [$managerAccountGuid];
        $managerAccountGuids = [$managerAccountGuid];
        /**
         *  -1 because we ignore learners
         */
        for ($i = 1; $i <= self::NUMBER_OF_MANAGER_LEVELS - 1; $i++) {
            $managerAccountsOfLevel = $this->getDirectlyManagedAccounts($portalId, $managerAccountGuidsOfLevel);
            $managerAccountGuidsOfLevel = array_map(fn (AccountUserDto $userAccountDto): string => $userAccountDto->getAccountGuid(), $managerAccountsOfLevel);
            $managerAccountGuids = array_merge($managerAccountGuids, $managerAccountGuidsOfLevel);
        }
        return $managerAccountGuids;
    }

    /**
     * @param int[] $userIds
     * @return array<int,AccountUserDto>
     */
    public function loadAccountsFromUserIdsWithPaging(int $portalId, array $userIds, ?int $managerAccountId = null): array
    {
        $userIdsToAccountsMap = [];
        $managerAccountGuid = $managerAccountId ? $this->userDomainHelper->loadPortalAccount($managerAccountId)->ulid : null;
        $allManagerAccountGuidsUnderManager = $managerAccountGuid ? $this->getAllManagerAccountGuidsForManager($portalId, $managerAccountGuid) : null;

        $userIdsChunks = array_chunk($userIds, self::USER_MANAGEMENT_PAGE_SIZE);
        foreach ($userIdsChunks as $userIdsChunk) {
            $userIdsToAccountsMap += $this->loadAccountsFromUserIds($portalId, $userIdsChunk, $managerAccountId, $allManagerAccountGuidsUnderManager, self::USER_MANAGEMENT_PAGE_SIZE);
        }
        return $userIdsToAccountsMap;
    }
}
