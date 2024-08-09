<?php

namespace go1\enrolment\controller\create;

use Doctrine\Common\Cache\Cache;
use go1\enrolment\services\lo\LoService;
use GuzzleHttp\Client;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function json_decode;

class PortalMiddleware
{
    private LoService $loService;
    private Cache $cache;
    private Client $http;
    private string $portalUrl;

    public function __construct(LoService $loService, Cache $cache, Client $http, string $portalUrl)
    {
        $this->loService = $loService;
        $this->cache = $cache;
        $this->http = $http;
        $this->portalUrl = $portalUrl;
    }

    public function __invoke(Request $req): ?JsonResponse
    {
        if ($learningObject = $this->getLearningObject($req)) {
            if ($portal = $this->fetch($learningObject)) {
                if ($portal instanceof JsonResponse) {
                    return $portal;
                }

                $req->attributes->add(['portal' => $portal]);
            }
        }

        return null;
    }

    private function getLearningObject(Request $req): ?stdClass
    {
        $learningObjects = $req->attributes->get('learningObjects', []);
        foreach ($learningObjects as $learningObject) {
            return $learningObject;
        }
        return null;
    }

    /**
     * @return stdClass|JsonResponse|null
     */
    private function fetch(stdClass $learningObject)
    {
        if ($learningObject->origin_id) {
            return ($origin = $this->loService->load($learningObject->origin_id))
                ? $this->fetch($origin)
                : new JsonResponse(['message' => 'Origin learning object not found: ' . $learningObject->origin_id], 400);
        }

        try {
            $cacheId = 'enrolment:portal:' . $learningObject->instance_id;
            if ($portal = $this->cache->fetch($cacheId)) {
                return $portal;
            }

            $res = $this->http->get($this->portalUrl . '/' . $learningObject->instance_id);
            $portal = json_decode($res->getBody()->getContents());
            $portal->public_key = $portal->data->public_key ?? '';
            $this->cache->save($cacheId, $portal, 30);

            return $portal;
        } catch (\Exception $e) {
            return new JsonResponse(['message' => 'Instance not found: ' . $learningObject->instance_id], 400);
        }
    }
}
