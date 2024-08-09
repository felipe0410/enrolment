<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use Exception;
use go1\enrolment\controller\create\PaymentMiddleware;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\model\Enrolment;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ManualPaymentController
{
    private LoggerInterface $logger;
    private ConnectionWrapper $db;
    private EnrolmentRepository $repository;
    private AccessChecker $accessChecker;
    private ?Enrolment $enrolment;
    private PaymentMiddleware $paymentClient;

    public function __construct(
        LoggerInterface $logger,
        ConnectionWrapper $db,
        EnrolmentRepository $repository,
        AccessChecker $accessChecker,
        PaymentMiddleware $paymentClient
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
        $this->paymentClient = $paymentClient;
    }

    public function accept(int $enrolmentId, Request $req): JsonResponse
    {
        try {
            $this->validate($enrolmentId, $req);

            $user = $this->accessChecker->validUser($req);

            $data = &$this->enrolment->data;
            $transactionId = isset($data->transaction->id) ? $data->transaction->id : 0;

            if (!$this->paymentClient->updateTransaction($transactionId)) {
                return new JsonResponse(['message' => 'Failed to update transaction.'], 500);
            }

            $data->history[] = [
                'action'    => 'manual_payment_accepted',
                'actorId'   => $user->id,
                'status'    => EnrolmentStatuses::NOT_STARTED,
                'timestamp' => time(),
            ];

            $this->repository->update($this->enrolment->id, ['status' => EnrolmentStatuses::NOT_STARTED, 'data' => json_encode($data)]);

            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Errors on accept manual payment', [
                'exception' => $e,
            ]);

            return Error::jr500('Internal error');
        }
    }

    public function reject(int $enrolmentId, Request $req): JsonResponse
    {
        try {
            // in order to get actor id for the enrolment upsert
            if (!$user = $this->accessChecker->validUser($req)) {
                return new JsonResponse(['message' => 'Invalid or missing JWT.'], 403);
            }

            $this->validate($enrolmentId, $req);

            return $this->repository->deleteEnrolment($this->enrolment, $user->id ?? 0, true, null, false, true)
                ? new JsonResponse(null, 204)
                : new JsonResponse(['message' => 'Can not archive enrolment.'], 500);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Internal error.');
        }
    }

    private function validate(int $enrolmentId, Request $request): void
    {
        $validEnrolment = false;
        $this->enrolment = EnrolmentHelper::loadSingle($this->db->get(), $enrolmentId);
        $lo = null;
        if (is_object($this->enrolment)) {
            $validEnrolment = EnrolmentStatuses::PENDING == $this->enrolment->status;
            $lo = $this->repository->loService()->load($this->enrolment->loId);
        }

        $user = $this->accessChecker->validUser($request);
        $validManualPayment = false;
        $validManualPaymentRecipient = false;
        if (is_object($lo)) {
            $loChecker = new LoChecker();
            $validManualPayment = $loChecker->manualPayment($lo);
            $validManualPaymentRecipient = is_object($user) && ($user->mail == $loChecker->manualPaymentRecipient($lo));
        }

        Assert::lazy()
            ->that($lo, 'lo', 'Invalid learning object.')->isObject()
            ->that($user, 'lo.assign', 'Invalid learning object assignment.')->isObject()
            ->that($validManualPayment, 'lo.manual-payment', 'This learning object is not configured for manual payment.')->true()
            ->that($validManualPaymentRecipient, 'lo.manual-payment.recipient', 'Invalid learning object recipient.')->true()
            ->that($validEnrolment, 'enrolment', 'Invalid enrolment.')->true()
            ->verifyNow();
    }
}
