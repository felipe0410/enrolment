<?php

namespace go1\enrolment\services;

use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Exception;
use stdClass;

class ContentSubscriptionService
{
    private Client $client;
    private string $contentSubscriptionUrl;
    private LoggerInterface $logger;

    public function __construct(
        Client $client,
        string $contentSubscriptionUrl,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->contentSubscriptionUrl = $contentSubscriptionUrl;
        $this->logger = $logger;
    }

    /**
     * Checks if a user has a subscription. This method will attach the result to the LO object, ensuring that the endpoint is only hit at most once per LO
     *
     * @param stdClass $lo
     * @param int $userId
     * @param ?int $portalId
     * @return bool
     */
    public function hasSubscription(&$lo, $userId, $portalId = null): bool
    {
        if (isset($lo->hasSubscription)) {
            return $lo->hasSubscription;
        }

        $sub = $this->checkForLicense($userId, $lo->id, $portalId);

        $lo->hasSubscription = $sub;

        return $lo->hasSubscription;
    }

    public function checkForLicense($userId, $loId, $portalId = null): bool
    {
        try {
            $res = $this->client->post($this->contentSubscriptionUrl . '/claim_license', [
                'headers' => [
                    'Authorization' => "Bearer " . UserHelper::ROOT_JWT,
                ],
                'json' => [
                    'user_id' => $userId,
                    'portal_id' => $portalId,
                    'lo_id' => $loId
                ]
            ]);

            if ($res->getStatusCode() === 200) {
                $body = json_decode($res->getBody()->getContents());

                return isset($body->status) && $body->status === 'OK';
            }

            return false;
        } catch (Exception $e) {
            $this->logger->info("Claiming license failed", ['lo_id' => $loId, 'exception' => $e]);

            return false;
        }
    }

    public function getSubscriptionStatus($userId, $portalId, $loId): ?int
    {
        try {
            $res = $this->client->get("$this->contentSubscriptionUrl/check_status/$userId/$portalId/$loId", [
                "headers" => [
                    'Authorization' => 'Bearer ' . UserHelper::ROOT_JWT
                ],
            ]);

            if ($res->getStatusCode() === 200) {
                $body = json_decode($res->getBody()->getContents());

                return $body->status;
            }

            return null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get license status of {$userId}/{$portalId}/{$loId}", ['exception' => $e]);

            return null;
        }
    }
}
