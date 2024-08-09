<?php

namespace go1\enrolment\controller\create;

use go1\util\lo\LiTypes;
use go1\util\user\UserHelper;
use go1\util\Error;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class LTIConsumerClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $ltiConsumerUrl;

    public function __construct(Client $httpClient, LoggerInterface $logger, string $ltiConsumerUrl)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->ltiConsumerUrl = $ltiConsumerUrl;
    }

    public function getLTIRegistration(object $enrolment): JsonResponse
    {
        try {
            $res = $this->httpClient->get("$this->ltiConsumerUrl/progress/$enrolment->id", [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . UserHelper::ROOT_JWT
            ]
            ]);

            return new JsonResponse(json_decode($res->getBody()->getContents()));
        } catch (BadResponseException $e) {
            $this->logger->error('Cannot load registrations for enrolment.', [
                'enrolmentId' => $enrolment->id,
                'exception'   => $e
            ]);
            return Error::jr("Cannot load registrations for enrolment - {$enrolment->id}.");
        }
    }
}
