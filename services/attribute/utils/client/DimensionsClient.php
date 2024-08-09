<?php

namespace go1\core\learning_record\attribute\utils\client;

use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;

class DimensionsClient
{
    private string $dimensionsUrl;
    private LoggerInterface $logger;
    private Client $client;

    public function __construct(string $dimensionsUrl, LoggerInterface $logger, Client $client)
    {
        $this->dimensionsUrl = $dimensionsUrl;
        $this->logger = $logger;
        $this->client = $client;
    }

    public function getDimensions(int $type, int $level = 1): array
    {
        try {
            $res = $this->client->get("$this->dimensionsUrl/browse?type=${type}&level=${level}", [
                "headers" => [
                    'Authorization' => 'Bearer ' . UserHelper::ROOT_JWT
                ],
            ]);
            if ($res->getStatusCode() === 200) {
                $content = json_decode($res->getBody()->getContents(), true);
                return array_column(array_values($content["hits"]), 'name');
            }
            $this->logger->error('[#enrolment-getDimensions] Can not get external learning types.', [
                'statusCode' => $res->getStatusCode(),
                'message'    => $res->getBody()->getContents()
            ]);
            return [];
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->logger->error('[#enrolment-getDimensions] Can not get external learning types.', [
                'message' => $response->getBody()->getContents()
            ]);

            return [];
        }
    }
}
