<?php

namespace go1\core\learning_record\plan;

use Assert\LazyAssertionException;
use Exception;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Marshaller;
use go1\core\util\client\federation_api\v1\schema\input\PortalFilter;
use go1\core\util\client\federation_api\v1\schema\object\LearningPlan;
use go1\core\util\client\federation_api\v1\schema\query\getLearningPlan;
use go1\core\util\client\UserDomainHelper;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\Text;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use stdClass;

class PlanController
{
    private AccessChecker    $accessChecker;
    private UserDomainHelper $userDomainHelper;
    private GraphQLClient    $graphQLClient;
    private LoggerInterface  $logger;

    public function __construct(
        AccessChecker $accessChecker,
        UserDomainHelper $userDomainHelper,
        GraphQLClient $graphQLClient,
        LoggerInterface $logger
    ) {
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->graphQLClient = $graphQLClient;
        $this->logger = $logger;
    }

    public function get(int $planId, Request $req)
    {
        try {
            $portal = $this->accessChecker->contextPortal($req);
            if (!$portal || !$this->accessChecker->validUser($req)) {
                return Error::createMissingOrInvalidJWT();
            }

            if (!$plan = $this->loadPlan($planId, $portal->title)) {
                return Error::jr404('Plan object not found.');
            }

            if (!$this->canAccess($req, $plan, $portal->id)) {
                return Error::jr403('Only portal admin, owner and manager can view plan.');
            }

            return JsonResponse::fromJsonString(json_encode($this->format($plan), Text::JSON_ENCODING_OPTIONS));
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Errors occur while load plan object', [
                'exception'  => $e,
                'planId'     => $planId,
                'controller' => __CLASS__
            ]);

            return Error::jr500('Internal error.');
        }
    }

    private function canAccess(Request $req, LearningPlan $plan, int $portalId): bool
    {
        if ($this->accessChecker->isContentAdministrator($req, $plan->space->legacyId)) {
            return true;
        }

        # Owner
        $currentUser = $this->accessChecker->validUser($req);
        if ($currentUser->id == $plan->user->legacyId) {
            return true;
        }

        # Manager
        if ($currentAccount = $this->accessChecker->validAccount($req, $plan->space->legacyId)) {
            return $plan->user->account
                && $portalId == $plan->space->legacyId
                && $this->userDomainHelper->isManager($plan->space->subDomain, $currentAccount->id, $plan->user->account->legacyId, true);
        }

        return false;
    }

    private function format(LearningPlan $learningPlan): array
    {
        return [
            'id'        => $learningPlan->id,
            'legacyId'  => $learningPlan->legacyId,
            'dueDate'   => !$learningPlan->dueDate ? null : $learningPlan->dueDate->getTimestamp(),
            'createdAt' => !$learningPlan->createdAt ? null : $learningPlan->createdAt->getTimestamp(),
            'updatedAt' => !$learningPlan->updatedAt ? null : $learningPlan->updatedAt->getTimestamp(),
            'user'      => [
                'legacyId'  => $learningPlan->user->legacyId,
                'firstName' => $learningPlan->user->firstName,
                'lastName'  => $learningPlan->user->lastName,
                'email'     => $learningPlan->user->email,
                'avatarUri' => $learningPlan->user->avatarUri,
                'status'    => ($learningPlan->user->status == 'ACTIVE') ? true : false,
            ],
            'author'    => !empty($learningPlan->author)
                ? [
                    'legacyId'  => $learningPlan->author->legacyId,
                    'firstName' => $learningPlan->author->firstName,
                    'lastName'  => $learningPlan->author->lastName,
                    'email'     => $learningPlan->author->email,
                    'avatarUri' => $learningPlan->author->avatarUri,
                    'status'    => ($learningPlan->author->status == 'ACTIVE') ? true : false,
                ] : null,
        ];
    }

    private function loadPlan(int $id, string $instance): ?LearningPlan
    {
        $getPlan = new getLearningPlan();
        $getPlan
            ->withArguments(
                $getPlan::arguments()
                ->withId(base64_encode("go1:LearningPlan:gc_plan.$id"))
            )
            ->withFields(
                $getPlan::fields()
                ->withAllScalarFields()
                ->withAuthor(
                    $getPlan::fields()::author()
                    ->withAllScalarFields()
                )
                ->withAuthor(
                    $getPlan::fields()::author()
                    ->withAllScalarFields()
                )
                ->withUser(
                    $getPlan::fields()::user()
                    ->withAllScalarFields()
                    ->withAccount(
                        $getPlan::fields()::user()::account()
                        ->withArguments(
                            $getPlan::fields()::user()::account()::arguments()
                            ->withPortal(PortalFilter::create()->name($instance))
                        )
                        ->withFields(
                            $getPlan::fields()::user()::account()::fields()
                            ->withLegacyId()
                        )
                    )
                )
                ->withSpace(
                    $getPlan::fields()::space()
                    ->withLegacyId()
                    ->withSubDomain()
                )
            );

        return $getPlan->execute($this->graphQLClient, new Marshaller());
    }
}
