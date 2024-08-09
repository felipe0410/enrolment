<?php

namespace go1\enrolment\controller\create;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\services\lo\LoService;
use go1\util\Error;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LegacyPaymentClient
{
    private LoggerInterface $logger;
    private LoService $loService;
    private Client $client;
    private UserDomainHelper $userDomainHelper;
    private string $entityUrl;
    private string $accountsName;
    private bool $isClone = false;

    public function __construct(
        LoggerInterface $logger,
        LoService $loService,
        Client $client,
        UserDomainHelper $userDomainHelper,
        string $entityUrl,
        string $accountsName
    ) {
        $this->logger = $logger;
        $this->loService = $loService;
        $this->client = $client;
        $this->userDomainHelper = $userDomainHelper;
        $this->entityUrl = rtrim($entityUrl, '/');
        $this->accountsName = $accountsName;
    }

    private function systemVariables($portal): array
    {
        static $variables;

        if (!$variables) {
            $user = $this->userDomainHelper->loadUserByEmail("user.1@{$this->accountsName}");
            if (!$user) {
                throw new HttpException(500, "Unable to get portal private key: {$portal->title}");
            }

            $accountsPrivateKey = $user->uuid;

            try {
                $scheme = strpos($this->accountsName, 'gocatalyze.com') ? 'https' : 'http';
                $url = "{$scheme}://{$this->accountsName}/api/1.0/custom/gc/variables/apiom_accounts_payment_stripe_secret_key,apiom_accounts_payment_commission_rate.json?api_key={$accountsPrivateKey}";
                $variables = json_decode($this->client->get($url)->getBody()->getContents(), true);
            } catch (BadResponseException $e) {
                $this->logger->critical("[enrolment.payment.legacy] Failed to get variables from #accounts", ['exception' => $e]);

                throw new RuntimeException('Failed to get system configuration.');
            }
        }

        return $variables;
    }

    private function portalStripeId($portal)
    {
        static $portalStripeConnectionId = [];

        if (!isset($portalStripeConnectionId[$portal->title])) {
            $portalStripeConnectionId[$portal->title] = null;
            $u1 = $this->userDomainHelper->loadUserByEmail("user.1@{$portal->title}");
            if ($u1) {
                $connections = $this->client->get("{$this->entityUrl}/find/payment/stripe_oauth", ['query' => ['f' => "field_person,target_id,{$u1->profileId}"]]);
                if (200 == $connections->getStatusCode()) {
                    $connections = json_decode($connections->getBody()->getContents(), true);
                    if ($connections && $portalConnection = reset($connections)) {
                        $portalStripeConnectionId[$portal->title] = $portalConnection['field_oauth_code']['und'][0]['value'];
                    }
                }
            }
        }

        return $portalStripeConnectionId[$portal->title];
    }

    public function process($portal, $learningObject, $stripeCardToken, $stripeCustomerId, $user, Request $req)
    {
        if ($learningObject->origin_id) {
            $this->isClone = true;
            $origin = $this->loService->load($learningObject->origin_id);

            return $origin
                ? $this->process($portal, $origin, $stripeCardToken, $stripeCustomerId, $user, $req)
                : new JsonResponse(['message' => 'Origin course is found not.'], 404);
        }

        if (!$portalStripeAccountId = $this->portalStripeId($portal)) {
            return Error::jr406('Can not found Stripe connection in portal.');
        }

        try {
            $variables = $this->systemVariables($portal);
            $stripeSecretKey = $variables['apiom_accounts_payment_stripe_secret_key'];
            $commissionRate = $variables['apiom_accounts_payment_commission_rate'];

            Assert::lazy()
                  ->that($stripeSecretKey, 'system')->string()->notEmpty()
                  ->that($commissionRate, 'system')->numeric()
                  ->that($stripeCardToken, 'token')->nullOr()->string()
                  ->that($stripeCustomerId, 'customerId')->nullOr()->string()
                  ->verifyNow();

            if (empty($stripeCardToken)) {
                if (empty($stripeCustomerId)) {
                    return new JsonResponse(['message' => 'Missing or invalid token or customerId.'], 400);
                }
            }

            $this->doProcess($req, $stripeSecretKey, $commissionRate, $portalStripeAccountId, $portal, $learningObject, $stripeCardToken, $stripeCustomerId, $user);
        } catch (LazyAssertionException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (BadResponseException $e) {
            return new JsonResponse(['message' => $e->getMessage()]);
        } catch (Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], 500);
        }
    }

    private function doProcess(Request $req, $stripeSecretKey, $commissionRate, $portalStripeAccountId, $portal, $lo, $stripeCardToken, $stripeCustomerId, $user): void
    {
        $transactionRes = $this
            ->client
            ->post("{$this->entityUrl}/payment", ['json' => [
                'type'                            => 'transaction',
                'field_learning_object_reference' => ['und' => [['target_id' => $lo->id]]],
                'field_person'                    => ['und' => [['target_id' => $user->profile_id]]],
                'field_transaction_response'      => ['und' => [['value' => json_encode(['status' => 'Payment is processing'])]]],
            ]]);

        try {
            $transaction = json_decode($transactionRes->getBody()->getContents());
            $fee = $this->isClone ? ['application_fee' => $lo->pricing->price * $commissionRate / 100] : [];
            $res = $this->client->post($url = 'https://api.stripe.com/v1/charges', [
                'auth'        => [$stripeSecretKey, null],
                'header'      => ['Stripe-Account' => $portalStripeAccountId, 'Content-Type' => 'application/json'],
                'form_params' => array_filter([
                        'amount'        => ((float) $lo->pricing->price) * 100,
                        'currency'      => $lo->pricing->currency,
                        'source'        => $stripeCardToken,
                        'customer'      => $stripeCustomerId,
                        'receipt_email' => $user->mail,
                        'metadata'      => [
                            'profile_id'   => $user->profile_id,
                            'mail'         => $user->mail,
                            'loId'         => $lo->id,
                            'loTitle'      => $lo->title,
                            'instanceId'   => $portal->id,
                            'instanceName' => $portal->title,
                            'orderId'      => $transaction->id,
                        ],
                    ]) + $fee,
            ]);

            if (200 == $res->getStatusCode()) {
                $body = $res->getBody()->getContents();
                $req->attributes->set('transaction', $transaction);
            }
            if (!empty($body)) {
                $this->client->patch(
                    "$this->entityUrl/payment/{$transaction->id}",
                    [
                        'json' => [
                            ['op' => 'replace', 'path' => '/field_transaction_response/und/0/value', 'value' => $body],
                        ],
                    ]
                );
            }
        } catch (BadResponseException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            if ($json = json_decode($body)) {
                $body = $json;
            }
            throw new Exception($body);
        }
    }
}
