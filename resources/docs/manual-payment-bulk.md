Bulk manual payment
====

# Bulk create

## Request

POST api.go1.co/enrolment/manual-payment/bulk/LO_ID/QUANTITY/CREDIT_TYPE?jwt=JWT

- LO_ID < int: ID if the learning object.
- QUANTITY < int: item quantity.
- CREDIT_TYPE < int: 0 or 1
- DESCRIPTION < string: user comments

## Response

- 400 If invalid input.
- 500 If internal error.
- 200 {"id": RO_ID}

```
Example

POST api.go1.co/enrolment/manual-payment/bulk/1000/10/0?jwt=JWT
    -H 'Content-Type: application/json'
    -d '{
        "description": "user comments"
    }'
```

# Bulk accept

## Request

POST api.go1.co/enrolment/manual-payment/bulk/RO_ID/accept?jwt=JWT -d {
    "ids": INT[]
}

- RO_ID < int: RO id
- ids < INT[]

## Response

- 400 If invalid input.
- 500 If internal error.
- 204 null

# Bulk reject

## Request

POST api.go1.co/enrolment/manual-payment/bulk/RO_ID/reject?jwt=JWT

- RO_ID < int: RO id

## Response

- 400 If invalid input.
- 500 If internal error.
- 204 null
