Enrolment create
====

## How to access work
[Diagram](howto-access.md)

## Request

    POST api.go1.co/enrolment/INSTANCE/PARENT_LO_ID/LO_ID/enrolment/STATUS
        ?membershipId=MEMBERSHIP_ID&policyId=POLICY_ID&parentEnrolmentId=PARENT_ENROLMENT_ID
        &jwt=JWT

- INSTANCE < string|int: Current portal name or portal id.
- LO_ID < int: ID if the learning object.
- PARENT_LO_ID < int: Parent learning object Id.
    - If user is enrolling to a module, the ID of course should be also provided.
    - If user is enrolling to a LI, the ID of module should be also provided.
    - If the parent ID is not available (marketplace learning item), the value can be 0.
- PARENT_ENROLMENT_ID < int: Parent enrolment Id.
    - If user is enrolling to a module, the ID of course enrolment MUST be also provided.
    - If user is enrolling to a LI, the ID of module enrolment MUST be also provided.
- STATUS < string: Default is 'in-progress'
- In request body, we can provide `reEnrol=1` to archive current enrolment and create new one.
- In request body, we can provide `reCalculate=1` to force re-calculate parent enrolment completion.
- In request body, we can provide `dueDate` (DATE_ISO8601) to set due date for enrolment.
- data < array
    - See [Data Structure](enrolment-data-structure.md)
 
- In request body, we can provide `notify` to disable email notification.
- In request body, we can provide `startDate` (DATE_ISO8601) to support event start date.

### Deprecated:
- MEMBERSHIP_ID: Instance id that will by pass payment process. 
- POLICY_ID < string: Policy id that will by pass payment process(depends on policy type).

## Response

- 400 If invalid input.
- 403 If enrolment creation is not allowed.
- 500 If internal error.
- 200 {"id": ENROLMENT_ID}

## Enrol to commercial course

    // With Stripe gateway
    // ---------------------
    POST api.go1.co/enrolment/INSTANCE/PARENT_LO_ID/LO_ID/enrolment/STATUS?jwt=JWT
        -H 'Content-Type: application/json'
        -d '{
            "coupon": STRING
            "paymentMethod":  "stripe",
            "paymentOptions": {
                   "token":    "SOME_TOKEN_PROVIDED_BY_STRIPE" 
                OR "customer": "CUSTOMER_ID_PROVIDED_BY_STRIPE" 
                OR BOTH
            }
        }'
    
    // With Credit gateway
    // ---------------------
    POST api.go1.co/enrolment/INSTANCE/PARENT_LO_ID/LO_ID/enrolment/STATUS?jwt=JWT
        -H 'Content-Type: application/json'
        -d '{
            "paymentMethod":  "credit",
            "paymentOptions": {"token": "THE_CREDIT_TOKEN" }
        }'

Response

- 4xx { "message": "…" }
- 200 { "id": THE_ENROLMENT_ID }

## Enrol to multiple courses

    POST api.go1.co/enrolment/INSTANCE/enrolment
        -H 'Content-Type: application/json'
        -d {
            "coupon": STRING
            "paymentMethod":  "stripe",
            "paymentOptions": { 
                "token": "SOME_TOKEN_PROVIDED_BY_STRIPE" 
                OR "customer": "CUSTOMER_ID_PROVIDED_BY_STRIPE" 
                OR BOTH 
            },
            "items": [
                {"loId": 111, "parentLoId": 11, "status": "in-progress", "dueDate": "2017-08-03T09:30:38+0000"},
                {"loId": 222, "parentLoId": 11, "status": "in-progress"},
                {"loId": 333, "parentLoId": 11, "status": "in-progress", "startDate": "2018-04-16T15:16:17+0000"}
            ]
        }
        
    # With credit gateway
    
    POST api.go1.co/enrolment/INSTANCE/enrolment
        -H 'Content-Type: application/json'
        -d {
            "coupon": STRING
            "paymentMethod":  "credit",
            "paymentOptions": {
                "tokens": {
                    $TOKEN_1: { "productType": "lo", "productId: 111 },
                    $TOKEN_2: { "productType": "lo", "productId: 222 }
                }
            },
            "items": [
                {"loId": 111, "parentLoId": 11, "status": "in-progress", "dueDate": "2017-08-03T09:30:38+0000"},
                {"loId": 222, "parentLoId": 11, "status": "in-progress"},
                {"loId": 333, "parentLoId": 11, "status": "in-progress", "startDate": "2018-04-16T15:16:17+0000"}
            ]
        }

- 4xx { "message": "…" }
- 200 { LO_ID: { RESPONSE_CODE: {"id": ENROLMENT_ID } } }
    - Example: `{ "111": { "200": { "id": 111 } }, "222": { "500": { "message": "Some error message" } } }`

## Create enrolment for student

### Single

    POST api.go1.co/enrolment/INSTANCE/PARENT_LO_ID/LO_ID/enrolment/STUDENT_EMAIL/STATUS

### Multiple

    POST api.go1.co/enrolment/INSTANCE/enrolment/STUDENT_EMAIL
        -d {
            "coupon": STRING
            "paymentMethod":  "stripe",
            "paymentOptions": { 
                "token": "SOME_TOKEN_PROVIDED_BY_STRIPE" 
                OR "customer": "CUSTOMER_ID_PROVIDED_BY_STRIPE" 
                OR BOTH 
            },
            "items": [
                {"loId": 111, "parentLoId": 11, "status": "in-progress", "dueDate": "2017-08-03T09:30:38+0000"},
                {"loId": 222, "parentLoId": 11, "status": "in-progress"},
                {"loId": 333, "parentLoId": 11, "status": "in-progress", "startDate": "2018-04-16T15:16:17+0000"}
            ]
        }
