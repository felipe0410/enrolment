Enrolment data structure
====

- history < array
    - action < string: 'assigned' | 'updated' | 'updated-via-queue' | 'change-status'
    - actorId < int
    - status < string: null| 'in-progress' | 'completed'
    - original_status < string
    - pass < int
    - original_pass < int
    - timestamp < int
- duration < int
- transaction < array
    - id < int
    - email < string
    - status < int
    - amount < float
    - currency < string
    - data < object
    - created < int
    - updated < int
    - payment_method < string
    - items < array
        - id < int
        - transaction_id < int
        - product_type < string
        - product_id < int
        - qty < int
        - price < float
        - data < object
        - tax < float
        - tax_included < int
    - instance_id < int
    - local_id < int