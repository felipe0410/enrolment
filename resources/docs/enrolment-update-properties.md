Enrolment update properties
====

## Request

    PUT api.go1.co/enrolment/enrolment/ENROLMENT_ID/properties?jwt=JWT
        -H 'Content-Type: application/json'
        -d '{
            "duration": 123,
            "custom_certificate": "https://www.webmerge.me/merge/165010/5i5cae?&test=1&_cache=6ana5tc19p"
        }'

- ENROLMENT_ID < int: ID if the enrolment.

- duration < int
- custom_certificate < string

## Note
- To update custom_certificate, the enrollment must be completed course enrollment

## Response

- 400 If invalid input.
- 403 Invalid or missing JWT.
- 500 Can not update enrolment.
- 204 Update successfully
