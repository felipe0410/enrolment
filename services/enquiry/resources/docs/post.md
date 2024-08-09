POST
====

Create new enquiry request.

    POST api.go1.co/enrolment/enquiry/LO_ID/EMAIL?jwt=JWT
        -d {
            "enquireFirstName": STRING
            "enquireLastName": STRING
            "enquirePhone": STRING
            "enquireMessage": STRING
        }

- LO_ID < int: ID of learning object.
- EMAIL < string: Email of enquiring user.
- JWT < string: Token of student.
- In request body, we can provide `reEnquiry=1` to archive current completed enquiry request and create new one.
- Response:
    - 204 If OK - Return created enquiry id
        - id < int: enquiry id
    - 403 If missing or invalid jwt
    - 400 If student mail is difference with enquiry mail
    - 400 If invalid learning object
    - 400 If learning object is not available for enquiring action
    - 400 If invalid portal from provided learning object
    - 500 Internal error

Enquiry Administrate
====

## POST

Review enquiry request, accept or reject current enquiry, by administrator.

    POST api.go1.co/enrolment/admin/enquiry/LO_ID/EMAIL -d {
            "status": INTEGER
            "instance": INTERGER
        }

- status < string: accepted/rejected.
- instance < int|string: Id or title of taken portal.
- Response:
    - 204 If OK
    - 403 If missing or invalid jwt
    - 403 If user is not portal manager or student manager
    - 400 If invalid learning object
    - 400 If learning object is not available for enquiring action
    - 400 If invalid portal id
    - 400 If invalid enquiry mail
    - 400 If status value is not accepted or rejected.
    - 400 If invalid taken portal
    - 406 If student does not enquiry yet
    - 406 If enquiry is not pending
    - 406 If missing data for enquiry
    - 500 Internal error
