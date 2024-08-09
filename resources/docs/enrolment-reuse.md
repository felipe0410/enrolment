Enrolment re-use
====

## Request

    POST api.go1.co/enrolment/PORTAL_NAME_OR_ID/reuse-enrolment&jwt=JWT
        -d '{
                "parentEnrolmentId": PARENT_ENROLMENT_ID
                "reuseEnrolmentId":  REUSE_ENROLMENT_ID,
            }'

- PORTAL_NAME_OR_ID < string|int: Current portal name or portal id.
- PARENT_ENROLMENT_ID < int: Parent enrolment Id.
    - If user is enrolling to a module, the ID of course enrolment MUST be also provided.
    - If user is enrolling to a LI, the ID of module enrolment MUST be also provided.
- REUSE_ENROLMENT_ID < int: Id of reuse enrolment.

## Response

- 400 If invalid input.
- 403 If permission denied.
- 500 If internal error.
- 200 
    - id < int
    - profile_id < int
    - parent_lo_id < int
    - parent_enrolment_id < int
    - lo_id < int
    - instance_id < int
    - taken_instance_id < int
    - start_date < date
    - end_date < date
    - status < string
    - result < float
    - pass < int
