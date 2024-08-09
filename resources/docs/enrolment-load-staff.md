Enrolment load for Go1 Staff 
====
For Go1 Staff to find the first enrolment for a given LO (all enrolments can be supported in the future)
    GET api.go1.co/enrolment/staff/lo/LO_ID
    GET api.go1.co/enrolment/staff/lo/LO_ID?status=in-progress

## Params
- LO_ID < integer: LO ID
- JWT < string: 'Admin on Accounts' JWT
- status < in-progress|not-started|completed

## Response
- 400 If invalid type
- 403 If permission denied.
- 200 If OK - Return Enrolment object
    - id < int
    - lo_id < int
    - lo_type < string
    - taken_instance_id < int
    - profile_id < string
    - status < string
