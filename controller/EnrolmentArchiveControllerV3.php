<?php

namespace go1\enrolment\controller;

use Assert\LazyAssertionException;
use Exception;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\controller\create\validator\EnrolmentArchiveV3Validator;
use go1\enrolment\EnrolmentRepository;
use go1\util\Error;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\model\Enrolment;
use go1\util\plan\PlanRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnrolmentArchiveControllerV3
{
    private EnrolmentRepository $repoEnrol;
    private PlanRepository $repoPlan;
    private EnrolmentArchiveV3Validator $validator;
    private LoggerInterface $logger;

    public function __construct(
        EnrolmentRepository $repoEnrol,
        PlanRepository $repoPlan,
        EnrolmentArchiveV3Validator $validator,
        LoggerInterface $logger
    ) {
        $this->repoEnrol = $repoEnrol;
        $this->repoPlan = $repoPlan;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function archive(int $id, Request $req): JsonResponse
    {
        try {
            [$retainOriginal, $portal, $actor, $enrolment] =
                $this->validator->validateParameters($id, $req);

            // get plans
            $plans = $this->repoEnrol->loadPlansByEnrolment($id);

            // get origin type
            $originType = null;
            $tracking = $this->repoEnrol->loadEnrolmentTracking($enrolment->id);
            if ($tracking) {
                $originType = $tracking->original_enrolment_type;
            } else {
                $originType = $plans
                    ? EnrolmentOriginalTypes::I_ASSIGNED
                        : $originType = EnrolmentOriginalTypes::I_SELF_DIRECTED;
            }

            // check necessity
            $archiveEnrolment = ($originType == EnrolmentOriginalTypes::I_ASSIGNED) || !$plans || !$retainOriginal;

            // archive enrolment
            if ($archiveEnrolment) {
                $enrolment = Enrolment::create($enrolment);
                $this->repoEnrol->deleteEnrolment($enrolment, $actor->id, true, null, false, true);
                $this->repoEnrol->spreadCompletionStatus(
                    $enrolment->takenPortalId,
                    $enrolment->loId,
                    $enrolment->userId,
                    true
                );
            }

            // archive plans
            if ($plans) {
                foreach ($plans as $plan) {
                    $this->repoPlan->archive($plan->id, [], ['notify' => $retainOriginal]);
                    $this->repoEnrol->removeEnrolmentPlansByPlanId($plan->id);
                    $this->repoEnrol->deletePlanReference($plan->id);
                }
            }

            return new JsonResponse([], 204);
        } catch (LazyAssertionException $e) {
            return Error::createMultipleErrorsJsonResponse(null, $e);
        } catch (NotFoundHttpException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'error_code' => ErrorMessageCodes::ENROLLMENT_RESOURCE_NOT_FOUND
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getStatusCode());
        } catch (AccessDeniedHttpException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'error_code' => ErrorMessageCodes::ENROLLMENT_OPERATION_NOT_PERMITTED
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getStatusCode());
        } catch (Exception $e) {
            $this->logger->error("Failed to archive enrolment", [
                'exception' => $e
            ]);

            return Error::jr500('Internal server error.');
        }
    }
}
