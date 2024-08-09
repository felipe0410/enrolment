Delete
====

## By ID

Delete current enquiry by id. If you want to archive an enquiry, DO NOT use this endpoint.

    DELETE api.go1.co/enrolment/enquiry/ID?instance=INSTANCE&jwt=JWT
    
- ID < int: ID of enquiry.
- INSTANCE < int|string: ID or title of portal.
- JWT < string: Token of portal manager or admin.

- Response:
    - 204 If OK
    - 403 If missing or invalid jwt.
    - 403 If user is not GO1 staffs.
    - INSTANCE
    -   403 If user is not portal manager or student manager.
    - 400 If enquiry not found.
    - 400 If enquiry mail not found.
    - 400 If learning object not found.
    - 400 If portal not found.
    - 406 If This learning object is not available for enquiring action.
    - 500 Internal error.

## By email

Delete current enquiry by lo id and email. If you want to archive an enquiry, DO NOT use this endpoint.

    DELETE api.go1.co/enrolment/enquiry/LO_ID/student/EMAIL?jwt=JWT

- LO_ID < int: ID of learning object.
- EMAIL < string: Email of enquiring user.
- JWT < string: Token of portal manager or admin.

- Response:
    - 204 If OK
    - 403 If missing or invalid jwt.
    - 403 If user is not portal manager or student manager.
    - 400 If enquiry not found.
    - 400 If enquiry mail not found.
    - 400 If learning object not found.
    - 400 If portal not found.
    - 406 If This learning object is not available for enquiring action.
    - 500 Internal error.
