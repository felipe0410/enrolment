<?php

namespace go1\core\learning_record\attribute;

use Assert\LazyAssertionException;
use Exception;
use go1\core\learning_record\attribute\utils\client\DimensionsClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\dimensions\DimensionType;
use go1\util\Error;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentAttributeController
{
    private LoggerInterface $logger;
    private EnrolmentAttributeRepository $repository;
    private AccessChecker $accessChecker;
    private DimensionsClient $dimensionsClient;
    private UserDomainHelper $userDomainHelper;
    private EnrolmentRepository $enrolmentRepository;
    private PortalService $portalService;

    public function __construct(
        LoggerInterface $logger,
        EnrolmentAttributeRepository $repository,
        AccessChecker $accessChecker,
        DimensionsClient $dimensionsClient,
        UserDomainHelper $userDomainHelper,
        EnrolmentRepository $enrolmentRepository,
        PortalService $portalService
    ) {
        $this->logger = $logger;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
        $this->dimensionsClient = $dimensionsClient;
        $this->userDomainHelper = $userDomainHelper;
        $this->enrolmentRepository = $enrolmentRepository;
        $this->portalService = $portalService;
    }

    public function post(int $enrolmentId, Request $req)
    {
        $access = $this->access($req, $enrolmentId);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $dimensionTypeList = $this->dimensionsClient->getDimensions(DimensionType::EXTERNAL_ACTIVITY_TYPE);
        if (empty($dimensionTypeList)) {
            return Error::jr('Can not get dimensions list');
        }

        try {
            $attributes = AttributeCreateOptions::create($req->request, $enrolmentId, $dimensionTypeList);

            return $this->doPost($attributes, $enrolmentId);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (InvalidArgumentException $e) {
            return Error::jr($e);
        }
    }

    public function put(int $enrolmentId, Request $req)
    {
        $access = $this->access($req, $enrolmentId);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $dimensionTypeList = $this->dimensionsClient->getDimensions(DimensionType::EXTERNAL_ACTIVITY_TYPE);
        if (empty($dimensionTypeList)) {
            return Error::jr('Can not get dimensions list');
        }

        try {
            $attributes = AttributeUpdateOptions::create($req->request, $enrolmentId, $dimensionTypeList);

            return $this->doPut($enrolmentId, $attributes);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (InvalidArgumentException $e) {
            return Error::jr($e);
        }
    }

    private function access(Request $req, int $enrolmentId)
    {
        if (!$this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        $enrolment = $this->enrolmentRepository->load($enrolmentId);
        if (!$enrolment) {
            $revisions = $this->enrolmentRepository->loadRevisions($enrolmentId);
            if (empty($revisions)) {
                return Error::jr404('Enrolment not found.');
            }

            $enrolment = (object)[
                'loId'   => $revisions[0]->lo_id,
                'userId' => $revisions[0]->user_id,
            ];
        } else {
            $enrolment = (object) [
                'loId' => $enrolment->lo_id,
                'userId' => $enrolment->user_id
            ];
        }

        if (!$portal = $this->portalService->loadBasicByLoId($enrolment->loId)) {
            return Error::jr('Invalid enrolment.');
        }

        if (!$this->accessChecker->isPortalAdmin($req, $portal->title)) {
            if (!$user = $this->userDomainHelper->loadUser($enrolment->userId, $portal->title)) {
                return Error::jr('User not found.');
            }

            $actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title);
            $isManager = ($actorPortalAccount && !empty($user->account))
                ? $this->userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $user->account->legacyId)
                : false;
            if (!$isManager) {
                return Error::jr403('Only portal admin or manager can post enrolment attribute.');
            }
        }
    }

    private function doPost(array $attributes, int $enrolmentId)
    {
        try {
            foreach ($attributes as $attribute) {
                $ids[] = $this->repository->create($attribute);
            }

            $attributes = array_map(
                [$this, 'format'],
                !empty($ids) ? $this->repository->loadMultiple($ids) : []
            );
            $this->repository->publish($enrolmentId);

            return new JsonResponse($attributes, 201);
        } catch (Exception $e) {
            $this->logger->error('Failed to create enrolment attribute', ['exception' => $e]);
        }

        return Error::jr500('Failed to create enrolment attribute.');
    }

    private function doPut(int $enrolmentId, array $attributes)
    {
        try {
            foreach ($attributes as $attribute) {
                /** @var EnrolmentAttributes $attribute */
                if ($original = $this->repository->loadBy($enrolmentId, $attribute->key)) {
                    $attribute->id = $original->id;
                    $this->repository->update($attribute);
                } else {
                    $this->repository->create($attribute);
                }
            }
            $this->repository->publish($enrolmentId);

            return new JsonResponse(null, 204);
        } catch (Exception $e) {
            $this->logger->error('Failed to update enrolment attribute', ['exception' => $e]);
        }

        return Error::jr500('Failed to update enrolment attribute.');
    }

    private function format(EnrolmentAttributes $attribute)
    {
        return [
            'id'    => $attribute->id,
            'key'   => EnrolmentAttributes::machineName($attribute->key),
            'value' => json_decode($attribute->value) ?? $attribute->value,
        ];
    }
}
