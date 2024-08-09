<?php

namespace go1\enrolment\controller\create;

use go1\util\policy\Realm;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class LoAccessClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $loAccessUrl;
    private array $staticCache;
    private ?string $bearerToken = null;

    public const CAN_VIEW   = 'view';
    public const CAN_ACCESS = 'access';
    public const CAN_EDIT   = 'edit';
    public const CAN_DELETE = 'delete';

    public function __construct(Client $httpClient, LoggerInterface $logger, string $loAccessUrl)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->loAccessUrl = $loAccessUrl;
    }

    private function fetch(int $loId, int $userId = 0, int $portalId = 0): array
    {
        $cacheId = "{$loId}:{$userId}:{$portalId}";
        if (isset($this->staticCache[$cacheId])) {
            return $this->staticCache[$cacheId];
        }

        $options = [];
        if ($portalId) {
            $options['query'] = [
                'portalId' => $portalId
            ];
        }

        if ($this->bearerToken) {
            $options['headers'] = [
                'Authorization' => $this->bearerToken
            ];
        }

        // In order to bypass enrolment checking in lo-access service
        // per this ticket: https://go1web.atlassian.net/browse/LAT-2082
        $options['query']['excludedResolver'] = 'enrolment';

        $data = [
            'realm'   => [],
            'context' => new stdClass(),
        ];

        try {
            $response = $this->httpClient->get("$this->loAccessUrl/{$loId}", $options);
            if (Response::HTTP_OK == $response->getStatusCode()) {
                # Here is payload which is provided by lo-access
                # {"realm": []enum("view", "access", "edit", "delete"), "context": {"isPremium": boolean}

                $obj = json_decode($response->getBody()->getContents());
                $data['realm'] = $obj->realm ?? [];
                $data['context'] = $obj->context ?? $data['context'];
            }
        } catch (ConnectException $e) {
            $this->logger->error('Failed to establish connection to lo-access service', [
                'exception' => $e
            ]);
        } catch (BadResponseException $e) {
            $context = [
                'exception' => $e
            ];
            if ($e->getCode() == 404) {
                $this->logger->info('Learning object is archived or unavailable for the portal', $context);
            } else {
                $this->logger->error('Bad request', $context);
            }
        }

        $this->staticCache[$cacheId] = array_values($data);

        return $this->staticCache[$cacheId];
    }

    public function setAuthorization(string $bearerToken): LoAccessClient
    {
        $this->bearerToken = $bearerToken;

        return $this;
    }

    /**
     * Convert realm values from lo-access to go1\util\policy\Realm
     */
    public function realm(int $loId, int $userId = 0, int $portalId = 0): ?int
    {
        [$realms,] = $this->fetch($loId, $userId, $portalId);
        if (empty($realms)) {
            return null;
        }

        if (in_array(self::CAN_ACCESS, $realms)) {
            return Realm::ACCESS;
        }

        if (in_array(self::CAN_VIEW, $realms)) {
            return Realm::VIEW;
        }

        return null;
    }
}
