<?php

namespace go1\enrolment\controller\create;

use go1\core\util\client\federation_api\v1\UserMapper;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\services\UserService;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\error\Relay;
use GuzzleHttp\Exception\BadResponseException;
use stdClass;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FindStudentMiddleware
{
    use Relay;

    private UserDomainHelper    $userDomainHelper;
    private string              $accountsName;
    protected UserService       $userService;
    private AccessChecker       $accessChecker;

    public function __construct(UserDomainHelper $userDomainHelper, string $accountsName, UserService $userService, AccessChecker $accessChecker)
    {
        $this->userDomainHelper = $userDomainHelper;
        $this->accountsName = $accountsName;
        $this->userService = $userService;
        $this->accessChecker = $accessChecker;
    }

    public function __invoke(Request $req): ?Response
    {
        if ($studentMail = $req->attributes->get('studentMail')) {
            if (!$req->attributes->has('studentUser')) {
                $studentUser = $this->userDomainHelper->loadUserByEmail($studentMail);
                if ($studentUser) {
                    $studentUser = (object)UserMapper::toLegacyStandardFormat($this->accountsName, $studentUser);
                }
                $req->attributes->set('studentUser', $studentUser);
            }
        } elseif ($req->get('user_account_id')) {
            if ($user = $this->accessChecker->validUser($req)) {
                try {
                    $studentUser = $this->getUserAccount($req);
                    if ($studentUser->id !== $user->id) {
                        $req->attributes->set('studentUser', $studentUser);
                    }
                    $req->attributes->set('studentUserV3', $studentUser);
                    $req->attributes->set('instance', $studentUser->portal_id);
                } catch (BadResponseException $e) {
                    return $this->relayException($e);
                } catch (Exception $e) {
                    return Error::createMultipleErrorsJsonResponse(['message' => 'Internal server error', 'error_code' => ErrorMessageCodes::ENROLLMENT_SERVER_ERROR], null, Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
        }
        return null;
    }

    private function getUserAccount(Request $req)
    {
        $userAccountId = $req->get('user_account_id');
        $studentUser = $this->userService->get($userAccountId, 'accounts', $this->accessChecker->jwt($req));
        if ($studentUser) {
            $student = new stdClass();
            $student->id = $studentUser->user->_gc_user_id;
            $student->account_id = $userAccountId;
            $student->profile_id = 0;
            $student->portal_id = $studentUser->portal_id;
            $student->mail = $studentUser->user->email;
            $student->accounts[] = (object) ['instance' => $studentUser->portal_id];
            return $student;
        }
        return null;
    }
}
