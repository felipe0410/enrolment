Manual payment
====

# Create manual enrolment

## Request

POST api.go1.co/enrolment/INSTANCE/PARENT_LO_ID/LO_ID/enrolment/pending?jwt=JWT

- INSTANCE < string|int: Current portal name or portal id.
- LO_ID < int: ID if the learning object.
- PARENT_LO_ID < int: Parent learning object Id.
    - If user is enrolling to a module, the ID of course should be also provided.
    - If user is enrolling to a LI, the ID of module should be also provided.
    - If the parent ID is not available (marketplace learning item), the value can be 'null'.
- paymentMethod: cod
- paymentOptions: empty
- data < array
    - description < string: user comments

## Response

- 400 If invalid input.
- 403 If enrolment creation is not allowed.
- 500 If internal error.
- 200 {"id": ENROLMENT_ID}

```
Example

POST api.go1.co/enrolment/INSTANCE/PARENT_LO_ID/LO_ID/enrolment/pending?jwt=JWT
    -H 'Content-Type: application/json'
    -d '{
        "paymentMethod":  "cod",
        "paymentOptions": {},
        "data": {"description": "user comments"}
    }'
```

# Accept manual enrolment

## Request

POST api.go1.co/enrolment/enrolment/manual-payment/accept/{ENROLMENTID}?jwt=JWT

- ENROLMENTID: enrolment id

## Response

- 400 If invalid input.
- 500 If internal error.
- 204 null

# Reject manual enrolment

## Request

POST api.go1.co/enrolment/enrolment/manual-payment/reject/{ENROLMENTID}?jwt=JWT

- ENROLMENTID: enrolment id

## Response

- 400 If invalid input.
- 500 If internal error.
- 204 null
