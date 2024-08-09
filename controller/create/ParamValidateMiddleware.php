<?php

namespace go1\enrolment\controller\create;

use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use go1\core\learning_record\attribute\AttributeCreateOptions;
use go1\core\learning_record\attribute\EnrolmentAttributes;
use go1\core\learning_record\attribute\utils\client\DimensionsClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\controller\create\validator\LearningObjectValidator;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\dimensions\DimensionType;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\Error;
use go1\util\lo\LiTypes;
use go1\util\lo\LoHelper;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ParamValidateMiddleware
{
    private ConnectionWrapper $go1;
    private AccessChecker $access;
    private LearningObjectValidator $loValidator;
    private DimensionsClient $dimensionsClient;
    private UserDomainHelper $userDomainHelper;
    private PortalService $portalService;

    public function __construct(
        ConnectionWrapper $go1,
        AccessChecker $accessChecker,
        LearningObjectValidator $loValidator,
        DimensionsClient $dimensionsClient,
        UserDomainHelper $userDomainHelper,
        PortalService $portalService
    ) {
        $this->go1 = $go1;
        $this->access = $accessChecker;
        $this->loValidator = $loValidator;
        $this->dimensionsClient = $dimensionsClient;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    /**
     * @return int[]
     */
    private function learningObjectIds(Request $req): array
    {
        return ($loId = $req->attributes->get('loId')) ? [(int) $loId] : array_map(
            function ($item) {
                return (int) (is_object($item) ? $item->loId : $item['loId']);
            },
            $req->get('items', [])
        );
    }

    public function __invoke(Request $req): ?JsonResponse
    {
        if (!$actor = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$takenInPortal = $this->validTakenInstance($req)) {
            return Error::simpleErrorJsonResponse('Enrolment can not be associated with an invalid portal.');
        }

        $parentLearningObjectIds = $parentEnrolmentIds = $enrolmentStatuses = $dueDates = $startDates = $errors = [];
        $learningObjectIds = $this->learningObjectIds($req);
        $learningObjects = LoHelper::loadMultiple($this->go1->get(), $learningObjectIds);
        $studentUser = null;
        $studentAccountId = null;

        if ($studentUser = $req->attributes->get('studentUser')) {
            $studentAccountId = $this->userDomainHelper->loadUser($studentUser->id, $takenInPortal->title)->account->legacyId ?? null;
        }

        $allAuthorIdsForLo = self::allAuthorIdsForLos($this->go1->get(), $learningObjectIds);
        $allAssessorIdsForLo = self::allAssessorIdsForLos($this->go1->get(), $learningObjectIds);

        if ($studentUser) {
            if (!$this->access->isPortalAdmin($req, $takenInPortal->title)) {
                $actorPortalAccount = $this->access->validAccount($req, $takenInPortal->title);
                $isManager = ($actorPortalAccount && !empty($studentAccountId))
                    ? $this->userDomainHelper->isManager($takenInPortal->title, $actorPortalAccount->id, $studentAccountId)
                    : false;

                if (!$isManager && !('credit' === $req->request->get('paymentMethod'))) {
                    $managerErrors = $this->isManager($learningObjects, $actor->id, $allAuthorIdsForLo, $allAssessorIdsForLo);
                    if ($managerErrors) {
                        if (empty($req->attributes->get('internal_data.is_instructor'))) {
                            // Only show detailed error when have more than 1 error
                            if (1 == count($managerErrors)) {
                                return Error::simpleErrorJsonResponse('Only manager or course assessor can create enrolment for student.', 403);
                            } else {
                                $errors = array_merge($errors, $managerErrors);
                            }
                        }
                    }
                }
            }
        } else {
            $studentUser = $actor;
            $account = $this->access->validAccount($req, $takenInPortal->title);
            $studentAccountId = $account->id ?? null;
        }

        $parentIdsForLo = self::sourcesForTargets($this->go1->get(), EdgeTypes::LO_HAS_LO, $learningObjectIds);
        $authorIdsForLo = self::targetsForSources($this->go1->get(), [EdgeTypes::HAS_AUTHOR_EDGE], $learningObjectIds);
        $enrolmentAttributes = [];
        foreach ($learningObjects as &$learningObject) {
            if ($learningObject) {
                $this->loValidator->validate(
                    $studentUser,
                    $learningObject,
                    $learningObjectIds,
                    $parentEnrolmentIds,
                    $enrolmentStatuses,
                    $dueDates,
                    $startDates,
                    $errors,
                    $parentIdsForLo[$learningObject->id] ?? [],
                    $authorIdsForLo[$learningObject->id] ?? [],
                    $allAuthorIdsForLo[$learningObject->id] ?? [],
                    $takenInPortal,
                    $req,
                    $studentAccountId
                );
            }

            $reEnrol = $req->attributes->get('reEnrol');
            if (!$reEnrol) {
                $parentEnrolmentId = $parentEnrolmentIds[$learningObject->id] ?? 0;
                if ($enrolment = EnrolmentHelper::findEnrolment($this->go1->get(), $takenInPortal->id, $studentUser->id, $learningObject->id, $parentEnrolmentId)) {
                    $learningObject->enrolment = $enrolment;
                }
            }

            if ($learningObject->type == LiTypes::MANUAL) {
                if (null !== $reqAttributes = $req->request->get('attributes')) {
                    try {
                        $supportAttributes = [EnrolmentAttributes::S_TYPE, EnrolmentAttributes::S_PROVIDER, 'date'];
                        $attributes = is_array($reqAttributes) ? array_keys($reqAttributes) : [];
                        if (count(array_diff($supportAttributes, $attributes))) {
                            $errors[$learningObject->id][400][] = 'Attributes type, provider and date are required.';
                        }

                        $dimensionTypeList = $this->dimensionsClient->getDimensions(DimensionType::EXTERNAL_ACTIVITY_TYPE);
                        if (empty($dimensionTypeList)) {
                            $errors[$learningObject->id][400][] = 'Can not get dimensions list';
                        }

                        $enrolmentAttributes[$learningObject->id] = AttributeCreateOptions::validate($reqAttributes, $dimensionTypeList);
                        $enrolmentAttributes[$learningObject->id]['date'] = $reqAttributes['date'] ?? null;
                    } catch (LazyAssertionException|InvalidArgumentException $e) {
                        $errors[$learningObject->id][400][] = $e->getMessage();
                    }
                } else {
                    $errors[$learningObject->id][400][] = 'Attributes is required.';
                }
            }
        }

        if ($errors) {
            if ($req->attributes->get('isSlimEnrolment')) {
                // use new error message format
                return Error::createMultipleErrorsJsonResponse($this->formatErrors($errors), null, Error::BAD_REQUEST);
            } else {
                return new JsonResponse(['error' => $errors, 'message' => (count($learningObjects) > 1) ? 'Can not create enrolments.' : 'Can not create enrolment.'], 400);
            }
        }

        $req->attributes->set('learningObjects', $learningObjects);
        $req->attributes->set('enrolmentStatuses', $enrolmentStatuses);
        $req->attributes->set('dueDates', $dueDates);
        $req->attributes->set('startDates', $startDates);
        $req->attributes->set('parentLearningObjectIds', $parentLearningObjectIds);
        $req->attributes->set('takenInPortal', $takenInPortal);
        !empty($enrolmentAttributes) && $req->attributes->set('enrolmentAttributes', $enrolmentAttributes);
        return null;
    }

    /**
     * Given an array of target_ids in gc_ro, find the source_ids for each, filter by array of types
     * @param int[] $types
     * @param int[] $targetIds
     * @return array<int, int[]>
     */
    private static function sourcesForTargets(Connection $db, array $types, array $targetIds): array
    {
        if (!$targetIds) {
            return [];
        }

        $edges = $db->executeQuery(
            'SELECT target_id, source_id FROM gc_ro WHERE type IN (?) AND target_id IN (?)',
            [$types, $targetIds],
            [DB::INTEGERS, DB::INTEGERS]
        )->fetchAll(DB::OBJ);

        $sourcesForTargets = [];
        foreach ($edges as $edge) {
            $targetId = (int) $edge->target_id;
            if (empty($sourcesForTargets[$targetId])) {
                $sourcesForTargets[$targetId] = [];
            }
            $sourcesForTargets[$targetId] [] = (int) $edge->source_id;
        }
        return $sourcesForTargets;
    }

    /**
     * Given an array of source_ids in gc_ro, find the target_ids for each, filter by array of types
     * @param int[] $types
     * @param int[] $sourceIds
     * @return array<int, int[]>
     */
    private static function targetsForSources(Connection $db, array $types, array $sourceIds): array
    {
        if (!$sourceIds) {
            return [];
        }

        $edges = $db->executeQuery(
            'SELECT source_id, target_id FROM gc_ro WHERE type IN (?) AND source_id IN (?)',
            [$types, $sourceIds],
            [DB::INTEGERS, DB::INTEGERS]
        )->fetchAll(DB::OBJ);

        $targetsForSources = [];
        foreach ($edges as $edge) {
            $sourceId = (int) $edge->source_id;
            if (empty($targetsForSources[$sourceId])) {
                $targetsForSources[$sourceId] = [];
            }
            $targetsForSources[$sourceId] [] = (int) $edge->target_id;
        }
        return $targetsForSources;
    }

    /**
     * @param int[] $loIds
     * @return array<int, int[]>
     */
    private static function allParentIdsForLos(Connection $db, array $loIds): array
    {
        if (!$loIds) {
            return [];
        }

        $parentsForLo = self::sourcesForTargets($db, EdgeTypes::LO_HAS_CHILDREN, $loIds);
        $parentLoIds = [];
        foreach ($parentsForLo as $loId => $parentsOfLoId) {
            $parentLoIds = array_merge($parentLoIds, $parentsOfLoId);
        }

        $allParentsForParentLo = self::allParentIdsForLos($db, array_unique($parentLoIds));
        $allParentIdsForLo = [];
        foreach ($loIds as $loId) {
            $ids = [];
            if (isset($parentsForLo[$loId])) {
                foreach ($parentsForLo[$loId] as $parentLoId) {
                    if (isset($allParentsForParentLo[$parentLoId])) {
                        $ids = array_merge($ids, $allParentsForParentLo[$parentLoId]);
                    }
                }

                $ids = array_merge($ids, $parentsForLo[$loId]);
            }
            $allParentIdsForLo[$loId] = $ids;
        }
        return $allParentIdsForLo;
    }

    /**
     * @param int[] $loIds
     * @return array<int, int[]>
     */
    private static function allAuthorIdsForLos(Connection $db, array $loIds): array
    {
        $allParentLoIdsForLo = self::allParentIdsForLos($db, $loIds);
        $allLoIds = [];
        foreach ($loIds as $loId) {
            $allLoIds = array_merge($allLoIds, $allParentLoIdsForLo[$loId]);
            $allLoIds[] = $loId;
        }

        $authorIdsForLo = self::targetsForSources($db, [EdgeTypes::HAS_AUTHOR_EDGE], array_unique($allLoIds));
        $allAuthorIdsForLo = [];
        foreach ($loIds as $loId) {
            $allLoIds = $allParentLoIdsForLo[$loId];
            $allLoIds[] = $loId;
            $authorIds = [];
            foreach ($allLoIds as $id) {
                $authorIds = array_merge($authorIds, $authorIdsForLo[$id] ?? []);
            }
            $allAuthorIdsForLo[$loId] = array_unique($authorIds);
        }
        return $allAuthorIdsForLo;
    }

    /**
     * @param int[] $loIds
     * @return array<int, int[]>
     */
    private static function allAssessorIdsForLos(Connection $db, array $loIds): array
    {
        $allParentLoIdsForLo = self::allParentIdsForLos($db, $loIds);
        $allLoIds = [];
        foreach ($loIds as $loId) {
            $allLoIds = array_merge($allLoIds, $allParentLoIdsForLo[$loId]);
            $allLoIds[] = $loId;
        }
        $assessorIdsForLo = self::targetsForSources($db, [EdgeTypes::COURSE_ASSESSOR], array_unique($allLoIds));

        $allAssessorIdsForLo = [];
        foreach ($loIds as $loId) {
            $allLoIds = $allParentLoIdsForLo[$loId];
            $allLoIds[] = $loId;
            $assessorIds = [];
            foreach ($allLoIds as $id) {
                $assessorIds = array_merge($assessorIds, $assessorIdsForLo[$id] ?? []);
            }
            $allAssessorIdsForLo[$loId] = array_unique($assessorIds);
        }
        return $allAssessorIdsForLo;
    }


    /**
     * @param stdClass[] $los
     * @param array<int, int[]> $allAuthorIdsForLo
     * @param array<int, int[]> $allAssessorIdsForLo
     * @return array<int, array<int, string[]>>
     */
    private function isManager(array $los, int $userId, array $allAuthorIdsForLo, array $allAssessorIdsForLo): array
    {
        $managerErrors = [];
        foreach ($los as $lo) {
            if (!in_array($userId, $allAuthorIdsForLo[$lo->id] ?? []) && !in_array($userId, $allAssessorIdsForLo[$lo->id] ?? [])) {
                $managerErrors[(int) $lo->id][403][] = 'Only manager or course assessor can create enrolment for student.';
            }
        }

        return $managerErrors;
    }

    private function validTakenInstance(Request $req): ?stdClass
    {
        if (null !== $portalNameOrId = $req->attributes->get('instance')) {
            $portal = $this->portalService->load($portalNameOrId);
            $takenInPortal = $portal;
            if ($takenInPortal) {
                $req->attributes->set('instance', $takenInPortal->id);

                return $takenInPortal;
            }
        }

        return null;
    }

    private function formatErrors(array $errors): array
    {
        $errorData = ['message' => 'Can not create enrollment.', 'error_code' => ErrorMessageCodes::ENROLLMENT_VALIDATION_ERRORS];
        foreach ($errors as $ref => $additionalErrors) {
            foreach ($additionalErrors as $httpCode => $errorMessages) {
                foreach ($errorMessages as $errorMessage) {
                    $errorData['error'][] = ['message' => $errorMessage, 'ref' => $ref, 'http_code' => $httpCode];
                }
            }
        }
        return $errorData;
    }
}
