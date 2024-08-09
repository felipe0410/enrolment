Enrolment load
====

    GET api.go1.co/enrolment/ENROLMENT_ID?jwt=JWT
    GET api.go1.co/enrolment/lo/LO_ID?portalId=PORTAL_ID&courseId=&COURSE_ID&moduleId=MODULE_ID&jwt=JWT
    GET api.go1.co/enrolment/lo/INSTANCE_ID/LO_TYPE/LO_REMOTE_ID?jwt=JWT
    GET api.go1.co/enrolment/revision/ENROLMENT_REVISION_ID?jwt=JWT
## Params

- tree < 0|1: Default is 0.
    - 1: Load enrolment of course tree, only work if the LO is a `course`.
- includeLTIRegistrations < 0|1: Default is 0.
    - 1: append registrations data to each item of enrolment `items` with 'lti-consumer/progress/enrolmentId' API call, only works when the enrolment item `lo_type` is 'lti' and tree param is also enabled.
- PORTAL_ID < integer: Taken portal Id
- COURSE_ID < integer: Course Id
- MODULE_ID < integer: Module Id

:warning: PORTAL_ID, COURSE_ID and MODULE_ID is mandatory when load learning item enrolment within course.

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
