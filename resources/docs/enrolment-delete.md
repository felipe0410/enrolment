Enrolment delete by LO
====

DELETE api.go1.co/enrolment/LO_ID
    -H 'Authorization: Bearer THE_JWT_TOKEN'

- LO_ID < int: ID if the learning object.
- Response:
    - 404 If LO not found.
    - 404 If enrolment not found.
    - 403 If permission denied.
    - 200 OK

Enrolment archive 
====

DELETE api.go1.co/enrolment/enrolment/ENROLMENT_ID?archiveChild=ARCHIVE_CHILDREN
    -H 'Authorization: Bearer THE_JWT_TOKEN'

- ENROLMENT_ID < int: ID if the learning object.
- ARCHIVE_CHILDREN < bool: Archive all child enrollments or NOT
- Response:
    - 404 If enrolment not found.
    - 403 If permission denied.
    - 204 OK
