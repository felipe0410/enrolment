<?php

namespace go1\enrolment\controller\create;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\app\DomainService;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\ContentSubscriptionService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\payment\PaymentMethods;
use go1\util\payment\TransactionStatus;
use go1\util\policy\Realm;
use go1\util\portal\PortalChecker;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @TODO: If user already made a success payment transaction, go forward.
 * @TODO: GO1P-931 Notify portal admin if connection is no longer valid.
 * @TODO: Store transaction ID in enrolment.data?
 */
class PaymentMiddleware
{
    private LoggerInterface $logger;
    private ConnectionWrapper $read;
    private Client $client;
    private EnrolmentRepository $repository;
    private string $paymentUrl;
    private AccessChecker $accessChecker;
    private LegacyPaymentClient $legacyPayment;
    private LoChecker $loChecker;
    private PortalChecker $portalChecker;
    private string $accountsName;
    private ContentSubscriptionService $contentSubscriptionService;

    public function __construct(
        LegacyPaymentClient $legacyPayment,
        LoggerInterface $logger,
        ConnectionWrapper $read,
        Client $client,
        EnrolmentRepository $repository,
        string $paymentUrl,
        string $accountsName,
        ContentSubscriptionService $contentSubscriptionService
    ) {
        $this->legacyPayment = $legacyPayment;
        $this->logger = $logger;
        $this->read = $read;
        $this->client = $client;
        $this->repository = $repository;
        $this->paymentUrl = $paymentUrl;
        $this->accessChecker = new AccessChecker();
        $this->loChecker = new LoChecker();
        $this->portalChecker = new PortalChecker();
        $this->accountsName = $accountsName;
        $this->contentSubscriptionService = $contentSubscriptionService;
    }

    protected function isManager(Request $req, string $portalName, stdClass $user): bool
    {
        $loId = (int) $req->attributes->get('loId');

        return ((bool) $this->accessChecker->isPortalManager($req, $portalName)) || ($loId && $this->loChecker->isAuthor($this->read->get(), $loId, $user->id));
    }

    public function __invoke(Request $req): ?JsonResponse
    {
        $paymentMethod = $req->get('paymentMethod');
        $paymentOptions = $req->get('paymentOptions', []);
        $skipValidateOption = is_null($paymentMethod) ? true : PaymentMethods::skipValidateOption($paymentMethod);
        $paymentToken = isset($paymentOptions['token']) ? $paymentOptions['token'] : ($skipValidateOption ? '' : null);
        $paymentCustomerId = isset($paymentOptions['customer']) ? $paymentOptions['customer'] : ($skipValidateOption ? '' : null);
        $portal = $req->attributes->get('portal');
        $takenInPortal = $req->attributes->get('takenInPortal');
        $coupon = $req->get('coupon');
        $canCoupon = is_null($coupon) ? true : ('credit' !== $paymentMethod);
        $user = $this->accessChecker->validUser($req);
        $student = $req->attributes->get('studentUser', $user);
        $studentId = isset($student) ? $student->id : null;

        $byPassPayment = $req->get('admin') && $this->isManager($req, $portal->title, $user);
        $isManualPayment = true;

        if ($paymentMethod == PaymentMethods::MANUAL) {
            $isManualPayment = $this->accessChecker->isPortalAdmin($req, $portal->title) ? true : false;
        }

        if (!$portal) {
            return null; // allows creating an enrolment (TODO: is this existing condition from 'NameToPortalMiddleware' expected?)
        }

        $learningObjects = $req->attributes->get('learningObjects');

        // providing a payment method requires an LO
        if ($paymentMethod && (!$learningObjects || count($learningObjects) < 1)) {
            $this->logger->error('Missing learning objects to create an enrolment', [
                'portal'          => $portal,
                'learningObjects' => $learningObjects,
                'takenInPortal'   => $takenInPortal,
                'paymentMethod'   => $paymentMethod,
                'paymentOptions'  => $paymentOptions,
                'user'            => $user,
                'student'         => $student
            ]);
            return new JsonResponse('Missing learning objects to create an enrolment', 500);
        }

        if (!$learningObjects) {
            return null; // allows creating an enrolment (TODO: is this existing condition expected?)
        }

        $freeAccessPolicy = true;
        $isCommercial = false;
        foreach ($learningObjects as &$learningObject) {
            if ($learningObject->pricing->price) {
                $isCommercial = true;

                if (Realm::ACCESS != $learningObject->realm && !$this->contentSubscriptionService->hasSubscription($learningObject, $studentId, $takenInPortal->id)) {
                    $freeAccessPolicy = false;
                }
            }
        }

        if (!$isCommercial) {
            return null; // allows creating an enrolment because all content items are free
        }

        if ($freeAccessPolicy) {
            return null; // allows creating an enrolment because all content items are free
        }

        // Ignore check commercial course if there is an enrolment
        if (1 == count($learningObjects)) {
            if ($enrolment = EnrolmentHelper::findEnrolment($this->read->get(), $takenInPortal->id, $student->id, $learningObjects[0]->id)) {
                if (EnrolmentStatuses::NOT_STARTED == $enrolment->status) {
                    $req->attributes->set('transaction', (object) ['status' => 1]);

                    return null;
                }
            }
        }

        if (!$this->portalChecker->isVirtual($portal)) {
            return (count($learningObjects) > 1)
                ? Error::simpleErrorJsonResponse('We are not supporting purchase multiple items on legacy portal.', 406)
                : $this->legacyPayment->process($portal, $learningObjects[0], $paymentToken, $paymentCustomerId, $user, $req);
        }

        try {
            if ($byPassPayment) {
                return null; // allows creating an enrolment for managers
            }
            Assert::lazy()
                  ->that($paymentMethod, 'paymentMethod', 'Invalid payment method')->inArray(PaymentMethods::all())
                  ->that($isManualPayment, 'manualPayment', 'Only admin of portal that LO resides on can enrol user using manual payment.')->true()
                  ->that($paymentOptions, 'paymentOptions', 'Invalid payment options.')->isArray()
                  ->that($studentId, 'studentId', 'Failed to detect student ID.')->notEmpty()
                  ->that($coupon, 'coupon')->nullOr()->string()
                  ->that($canCoupon, 'coupon')->true('Can not use coupon on credit payment method')
                  ->verifyNow();

            return $this->process($portal, $learningObjects, $studentId, $paymentMethod, $paymentOptions, $paymentToken, $paymentCustomerId, $coupon, $req);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('[Middleware] Failed to process payment', [
                'portal'            => $portal,
                'studentId'         => $studentId,
                'paymentMethod'     => $paymentMethod,
                'paymentOptions'    => $paymentOptions,
                'paymentCustomerId' => $paymentCustomerId,
                'learningObjects'   => $learningObjects,
                'coupon'            => $coupon,
                'exception'         => $e
            ]);
            return Error::jr500('Failed to process payment. Please try again later');
        }
    }

    private function process(
        stdClass $portal,
        array $learningObjects,
        int $studentId,
        string $paymentMethod,
        array $paymentOptions,
        $paymentToken,
        $paymentCustomerId,
        $coupon,
        Request $req
    ): ?JsonResponse {
        try {
            $connectionId = ('credit' === $paymentMethod) ? null : (isset($portal->public_key) ? $portal->public_key : '');
            $cartOptions = $this->cartOptions($learningObjects, $connectionId, $studentId, $paymentMethod, $paymentOptions, $paymentToken, $paymentCustomerId, $coupon, $req);

            Assert::lazy()
                  ->that($connectionId, 'loId', 'The payment gateway is not configured yet.')->nullOr()->string()->notEmpty()
                  ->verifyNow();

            $res = $this->client->post("{$this->paymentUrl}/cart/process", [
                'headers' => UserHelper::authorizationHeader($req),
                'json'    => $cartOptions,
            ]);

            $transactionJson = $res->getBody()->getContents();
            if (!$transaction = json_decode($transactionJson)) {
                $this->logger->error('Failed to process the payment [500]', ['cartOptions' => $cartOptions]);
                return Error::simpleErrorJsonResponse('Failed to process the payment.', 500);
            }

            // don't create an enrolment if the transaction failed
            if (isset($transaction->status) && $transaction->status == TransactionStatus::FAILED) {
                $this->logger->error('Payment transaction failed. [402]', ['response' => $transactionJson, 'studentId' => $studentId]);
                return Error::simpleErrorJsonResponse('Payment transaction failed.', 402);
            }

            if ($paymentMethod == PaymentMethods::MANUAL) {
                $this->updateTransaction($transaction->id);
                $transaction->status = 1;
            }

            $req->attributes->set('transaction', $transaction);
            return null;
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->logger->error('Failed to process the payment [400]', ['exception'  => $e, 'studentId' => $studentId]);
            return new JsonResponse(
                $response->getBody()->getContents(),
                $response->getStatusCode(),
                ['Content-Type' => 'application/json']
            );
        }
    }

    private function cartOptions(
        array $learningObjects,
        $connectionId,
        $studentId,
        string $paymentMethod,
        array $paymentOptions,
        $stripeCardToken,
        $stripeCustomerId,
        $coupon,
        Request $req
    ): array {
        $user = $this->accessChecker->validUser($req);
        $options = [
            'timestamp'      => time(),
            'paymentMethod'  => $paymentMethod,
            'paymentOptions' =>
                ('stripe' !== $paymentMethod)
                    ? (PaymentMethods::skipValidateOption($paymentMethod) ? [] : ['userId' => $studentId, 'receipt_email' => $user->mail] + $paymentOptions)
                    : ['connectionUuid' => $connectionId, 'token' => $stripeCardToken, 'customer' => $stripeCustomerId],
            'cartOptions'    => array_filter(['coupon' => $coupon]),
            'metadata'       => ['enrolmentVersion' => DomainService::VERSION],
        ];

        $metadata = &$options['metadata'];
        $metadata = array_filter([
            'connection_id' => $connectionId,
            'buyer'         => $user->mail,
            'user_id'       => $studentId,
        ]);

        foreach ($learningObjects as $i => $learningObject) {
            if (!empty($learningObject->enrolment)) {
                continue;
            }

            $metadata["product_{$i}"] = "[{$learningObject->type}.{$learningObject->id}] {$learningObject->title}";
            $options['cartOptions']['items'][] = [
                'instanceId'   => $learningObject->instance_id,
                'productId'    => $learningObject->id,
                'type'         => 'lo',
                'price'        => $learningObject->pricing->price,
                'currency'     => $learningObject->pricing->currency,
                'tax'          => $learningObject->pricing->tax,
                'tax_included' => $learningObject->pricing->tax_included,
                'qty'          => 1,
                'data'         => ['title' => $learningObject->title],
            ];
        }

        if ($takenInPortal = $req->attributes->get('takenInPortal')) {
            $takenPortalId = $takenInPortal->id;
        }
        $options['cartOptions']['taken_portal_id'] = $takenPortalId ?? null;

        return $options;
    }

    public function updateTransaction($id): bool
    {
        try {
            $this->client->put("{$this->paymentUrl}/transaction/{$id}/complete");

            return true;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->logger->error(sprintf('Failed to update transaction #%d: %s.', $id, $response->getBody()->getContents()));

            return false;
        }
    }
}
