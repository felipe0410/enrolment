Enrolment load single
====
  ```
  GET api.go1.co/enrolment/lo/{loId}/{takenPortalId}?userId={userId}&parentLoId={parentLoId}jwt={JWT}
  ```
## Params

- userId < integer (optional): Id of user. Only portal admin can view enrolment of another.
- parentLoId < integer (optional): Id of parent learning object.

## Response

- 400 If invalid input
- 403 If permission denied.
- 200 If OK - Return Enrolment object
    - id < int
    - lo_id < int
    - lo_type < string
    - taken_instance_id < int
    - profile_id < string
    - status < string
