Plan archive
====

## Archive plan

    DELETE api.go1.co/enrolment/plan/{planId}?jwt=JWT
    
### Response

- 204 If OK
- 400 If invalid request
- 403 If invalid access right (Only portal administrator and manager)
- 404 If plan not found
- 500 If runtime error

## Archive plan from group

### Request

    POST api.go1.co/enrolment/plan/INSTANCE_ID/LO_ID/group/GROUP_ID?jwt=JWT
    
### Response

- 204 If OK
- 400 If invalid request
- 403 If invalid access right
- 404 If portal|lo|group not found
- 500 If runtime error
