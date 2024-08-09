Enrolment re-calculate
====

## Request

```
POST api.go1.co/enrolment/enrolment/re-calculate/ENROLMENT_ID?jwt=JWT
    -H 'Content-Type: application/json'
    -d '{
        "membership": "dev.mygo1.com"
    }'
```

- ENROLMENT_ID < int: ID if the enrolment.

- membership (OPTIONAL, when editing shared LO enrolment)
    + string: portal name
    + int: portal id

## Response

- 400 If invalid input.
- 403 Invalid or missing JWT.
- 404 Invalid enrolment id.
- 500 Can not re-calculate enrolment.
- 204 Update successfully

## Re-calculate permission
- Portal admin or GO1 staff.
- Student manager.
- L0 (parent LOs) assessor | Enrolment (parent enrolments) assessor.
- LO (parent LOs) author.
