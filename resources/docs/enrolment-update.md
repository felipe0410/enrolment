Enrolment update
====

## PERMISSIONS on enrolment status

- Student CAN manually change status of enrolment of simple LI: Text, Resource, Iframe, …
- Student CANNOT manually change enrolment.status of complex LI: Quiz, Scorm, Tincan, …
- Student CANNOT manually change enrolment.status of learning pathway, course, module.
- Portal administrator can manually change enrolment.status of all enrolment.

## Request

    PUT api.go1.co/enrolment/enrolment/ENROLMENT_ID?jwt=JWT
        -H 'Content-Type: application/json'
        -d '{
            "startDate": "2016-10-17T13:04:33+0700",
            "endDate": "2016-10-18T13:04:33+0700",
            "expectedCompletionDate": "2016-10-18T13:04:33+0700", //@deprecated
            "dueDate": "2017-08-03T09:30:38+0000",
            "status": STATUS,
            "result": 45,
            "pass": 0,
            "duration": 123,
            "note": "manual mark complete",
            "data": {
                "custom_certificate": "https://www.webmerge.me/merge/165010/5i5cae?&test=1&_cache=6ana5tc19p"
            }
        }'

- ENROLMENT_ID < int: ID if the enrolment.

- startDate < string (DATE_ISO8601)
- endDate < string (DATE_ISO8601)
- expectedCompletionDate < string (DATE_ISO8601) //@deprecated
- dueDate < string (DATE_ISO8601)
- status  < string: 'in-progress' | 'pending' | 'completed' | 'not-started'
- result  < float
- pass    < int: 0 | 1
- duration < int
- note    < string
- data    < object
    - custom_certificate < string
- In request body, we can provide `reCalculate=1` to force re-calculate parent enrolment completion.

## Response

- 400 If invalid input.
- 403 Invalid or missing JWT.
- 500 Can not update enrolment.
- 204 Update successfully

## Status permission

| Who      | From                              | To                                                   | On        | Allow |
|----------|-----------------------------------|------------------------------------------------------|-----------|-------|
| everyone | completed                         | any status                                           | LO/LI     |       |
| learner  | in-progress, not-started, pending | in-progress, not-started, pending, completed         | Simple LI | x     |
| learner  | in-progress, not-started, pending | in-progress, not-started, pending, completed         | LO/LI     |       |
| manager  | in-progress, not-started, pending | in-progress, not-started, pending, completed         | LO/LI     | x     |
| admin    | in-progress, not-started, pending | in-progress, not-started, pending, completed         | LO/LI     | x     |
| assessor | in-progress, not-started, pending | in-progress, not-started, pending, completed         | LO/LI     | x     |
| staff    | in-progress, not-started, pending | in-progress, not-started, pending, expire, completed | LO/LI     | x     |

