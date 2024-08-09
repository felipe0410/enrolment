<?php

namespace go1\enrolment\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

class ReportDataService
{
    private Client           $client;
    private string           $reportUrl;
    private LoggerInterface  $logger;

    public function __construct(Client $client, string $reportUrl, LoggerInterface $logger)
    {
        $this->client    = $client;
        $this->reportUrl = $reportUrl;
        $this->logger    = $logger;
    }

    public function getReportCounts(int $portalId, int $loId, string $jwt, array $filters): array
    {
        try {
            $response = $this->client->post("{$this->reportUrl}/portals/{$portalId}/learning-objects/{$loId}/enrollments/report", [
                RequestOptions::HEADERS => [
                    'Authorization' => "Bearer {$jwt}"
                ],
                RequestOptions::JSON => ['where' => $filters],
            ]);
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
            $this->logger->error('[#enrolment-getReportCounts] Can not get report counts.', [
                'portalId' => $portalId,
                'loId' => $loId,
                'statusCode' => $response->getStatusCode(),
                'message'    => $response->getBody()->getContents()
            ]);
            return [];
        } catch (BadResponseException $e) {
            $this->logger->error('[#enrolment-getReportCounts] Can not get report counts.', [
                'portalId' => $portalId,
                'loId' => $loId,
                'Exception' => $e,
            ]);
            throw $e;
        }
    }
}
