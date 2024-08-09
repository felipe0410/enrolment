<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use go1\clients\MqClient;
use go1\clients\PaymentClient;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\lo\LoHelper;
use go1\util\model\Edge;
use go1\util\user\Roles;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ManualPaymentBulkController
{
    private LoggerInterface $logger;
    private ConnectionWrapper $db;
    private MqClient $mqClient;
    private AccessChecker $accessChecker;
    private Client $client;
    private string $creditUrl;
    private PaymentClient $paymentClient;
    private UserDomainHelper $userDomainHelper;
    private PortalService $portalService;

    public function __construct(
        LoggerInterface $logger,
        ConnectionWrapper $db,
        MqClient $mqClient,
        AccessChecker $accessChecker,
        Client $client,
        string $creditUrl,
        PaymentClient $paymentClient,
        UserDomainHelper $userDomainHelper,
        PortalService $portalService
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->mqClient = $mqClient;
        $this->accessChecker = $accessChecker;
        $this->client = $client;
        $this->creditUrl = $creditUrl;
        $this->paymentClient = $paymentClient;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    public function post(int $loId, int $quantity, int $creditType, Request $req): JsonResponse
    {
        $user = $this->accessChecker->validUser($req);
        $description = strip_tags($req->get('description'));

        try {
            $lo = LoHelper::load($this->db->get(), $loId);

            $validLo = false;
            if (is_object($lo)) {
                $validLo = (new LoChecker())->manualPayment($lo);
            }

            Assert::lazy()
                  ->that($lo, 'lo', 'Invalid learning object.')->isObject()
                  ->that($validLo, 'lo.manual', 'Invalid manual payment learning object.')->true()
                  ->that($user, 'user', 'Invalid user.')->isObject()
                  ->that($creditType, 'credit type', 'Invalid credit type.')->numeric()->inArray([0, 1])
                  ->that($quantity, 'quantity', 'Invalid quantity.')->numeric()->min(1)
                  ->verifyNow();
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }

        $targetId = $this->countManualPaymentItems($loId);
        $data = [
            'quantity'    => $quantity,
            'description' => $description,
            'credit_type' => $creditType,
        ];

        $linkId = EdgeHelper::link($this->db->get(), $this->mqClient, EdgeTypes::HAS_MANUAL_PAYMENT, $loId, $targetId, $user->id, $data);

        return $linkId
            ? new JsonResponse(['id' => $linkId], 200)
            : new JsonResponse(['message' => 'Can not create multiple manual payment record.'], 500);
    }

    private function countManualPaymentItems(int $loId): int
    {
        $types = [EdgeTypes::HAS_MANUAL_PAYMENT, EdgeTypes::HAS_MANUAL_PAYMENT_ACCEPT, EdgeTypes::HAS_MANUAL_PAYMENT_REJECT];
        $max = $this->db->get()->executeQuery('SELECT MAX(target_id) FROM gc_ro WHERE type IN (?) AND source_id = ?', [$types, $loId], [Connection::PARAM_INT_ARRAY, DB::INTEGER]);

        return (int) $max->fetchColumn() + 1;
    }

    public function accept(int $roId, Request $req): JsonResponse
    {
        $user = $this->accessChecker->validUser($req);
        $ro = EdgeHelper::load($this->db->get(), $roId);
        try {
            $this->validate($user, $ro, $req);

            $creditQuantity = !empty($ro->data->quantity) ? $ro->data->quantity : 0;
            $creditType = !empty($ro->data->credit_type) ? $ro->data->credit_type : 0;

            $log = ['accept_bulk_manual' => $user->id];
            EdgeHelper::changeType($this->db->get(), $this->mqClient, $roId, EdgeTypes::HAS_MANUAL_PAYMENT_ACCEPT, $log);

            // Create requester credits
            $lo = LoHelper::load($this->db->get(), $ro->sourceId);

            $portal = $this->portalService->loadBasicById($lo->instance_id);
            if (!$owner = $this->userDomainHelper->loadUser($ro->weight)) {
                return Error::jr('User not found.');
            }
            $this->createCredits($ro->sourceId, $portal->title, $owner, $creditQuantity, $creditType);

            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }

    public function reject(int $roId, Request $req): JsonResponse
    {
        $user = $this->accessChecker->validUser($req);
        $ro = EdgeHelper::load($this->db->get(), $roId);

        try {
            $this->validate($user, $ro, $req);

            $log = ['reject_bulk_manual' => $user->id];
            EdgeHelper::changeType($this->db->get(), $this->mqClient, $roId, EdgeTypes::HAS_MANUAL_PAYMENT_REJECT, $log);

            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (\Exception $e) {
            $this->logger->error('Can not reject bulk manual enrolment', ['exception' => $e]);

            return Error::simpleErrorJsonResponse('Can not reject bulk manual enrolment.', 500);
        }
    }

    private function validate($user, Edge $edge, Request $req): void
    {
        $validLo = false;
        $validUser = false;
        $validRo = false;
        if (is_object($edge) && is_object($user)) {
            $lo = LoHelper::load($this->db->get(), $edge->sourceId);
            if ($lo) {
                $loChecker = new LoChecker();
                $accessChecker = new AccessChecker();
                $instance = $this->portalService->loadBasicById($lo->instance_id)->title;

                $validLo = $loChecker->manualPayment($lo);
                $validUser = $accessChecker->isPortalAdmin($req, $instance) || ($user->mail == $loChecker->manualPaymentRecipient($lo));
                $validRo = EdgeTypes::HAS_MANUAL_PAYMENT == $edge->type;
            }
        }

        Assert::lazy()
              ->that($validLo, 'lo.manual', 'Invalid manual payment learning object.')->true()
              ->that($user, 'user', 'Invalid user.')->isObject()
              ->that($validUser, 'user', 'Invalid user.')->true()
              ->that($validRo, 'ro', 'Invalid roId.')->true()
              ->verifyNow();
    }

    private function createCredits(int $loId, string $instance, User $user, int $creditQuantity, int $creditType): void
    {
        $user = (object) [
            'id'    => $user->legacyId,
            'mail'  => $user->email,
            'roles' => Roles::ACCOUNTS_ROLES,
        ];
        $authorization = 'Bearer ' . UserHelper::encode($user);

        try {
            $res = $this->client->post("{$this->creditUrl}/purchase/{$instance}/lo/{$loId}/{$creditQuantity}/{$creditType}", [
                'headers' => ['Content-Type' => 'application/json', 'Authorization' => $authorization],
                'json'    => ['paymentMethod' => 'cod'],
            ]);

            $credits = json_decode($res->getBody()->getContents());
            $transactionId = $credits[0]->transaction_id;
            $this->paymentClient->updateCODTransaction($transactionId);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();

            throw new \Exception($response->getBody()->getContents(), $response->getStatusCode());
        }
    }
}
