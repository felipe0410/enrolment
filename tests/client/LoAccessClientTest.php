<?php

namespace go1\enrolment\tests\client;

use go1\enrolment\controller\create\LoAccessClient;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\policy\Realm;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class LoAccessClientTest extends EnrolmentTestCase
{
    private int $portalId = 1;
    private int $loId     = 1;

    public function test()
    {
        $app = $this->getApp();

        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->any())
                ->method('get')
                ->willReturnCallback(function (string $url, array $options) use ($app) {
                    $this->assertEquals("http://lo-access.dev/{$this->loId}", $url);
                    $this->assertEquals($this->portalId, $options['query']['portalId']);

                    return new Response(200, [], json_encode(['realm' => ['access']]));
                });

            return $httpClient;
        });

        $app->extend(LoAccessClient::class, function () use ($app) {
            return new LoAccessClient($app['client'], $app['logger'], $app['lo-access_url']);
        });

        /*** @var $client LoAccessClient */
        $client = $app[LoAccessClient::class];
        $this->assertEquals(Realm::ACCESS, $client->realm($this->loId, 1, $this->portalId));
    }

    public function testFailedToConnect()
    {
        $app = $this->getApp();

        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->once())
                ->method('get')
                ->willReturnCallback(function (string $url, array $option) {
                    throw new ConnectException('curl reached timeout', $this->createMock(RequestInterface::class));
                });

            return $httpClient;
        });

        $app->extend(LoAccessClient::class, function () use ($app) {
            return new LoAccessClient($app['client'], $app['logger'], $app['lo-access_url']);
        });

        /*** @var $client LoAccessClient */
        $client = $app[LoAccessClient::class];
        $this->assertNull($client->realm($this->loId, 1, $this->portalId));
    }

    public function testBadResponseException()
    {
        $app = $this->getApp();

        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->once())
                ->method('get')
                ->willReturnCallback(function (string $url, array $option) {
                    throw new BadResponseException('invalid payload', $this->createMock(RequestInterface::class), $this->createMock(ResponseInterface::class));
                });

            return $httpClient;
        });

        $app->extend(LoAccessClient::class, function () use ($app) {
            return new LoAccessClient($app['client'], $app['logger'], $app['lo-access_url']);
        });

        /*** @var $client LoAccessClient */
        $client = $app[LoAccessClient::class];
        $this->assertNull($client->realm($this->loId, 1, $this->portalId));
    }

    public function testBypassEnrolCheck()
    {
        $app = $this->getApp();

        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->once())
                ->method('get')
                ->willReturnCallback(function (string $url, array $option) {
                    $this->assertEquals("http://lo-access.dev/{$this->loId}", $url);
                    $this->assertEquals('enrolment', $option['query']['excludedResolver']);
                    return new Response(200, [], json_encode(['realm' => ['access']]));
                });

            return $httpClient;
        });

        $app->extend(LoAccessClient::class, function () use ($app) {
            return new LoAccessClient($app['client'], $app['logger'], $app['lo-access_url']);
        });

        /*** @var $client LoAccessClient */
        $client = $app[LoAccessClient::class];
        $this->assertEquals(Realm::ACCESS, $client->realm($this->loId, 1, $this->portalId));
    }
}
