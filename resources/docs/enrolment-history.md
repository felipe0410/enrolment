Enrolment load history on a learning object
====

    GET api.go1.co/enrolment/lo/LO_ID/history/USER_ID?jwt=JWT

- LO_ID: Learning object ID 
- USER_ID (optional): User Id

- 403 If permission denied.
- 404 If learning object not found.
- 200 If OK - Return Enrolments object
    - id < int
    - lo_id < int
    - taken_instance_id < int
    - profile_id < string
    - status < string
    - start_date < date
    - end_date < date
    - due_date < date
    - result < int
    - pass < int
