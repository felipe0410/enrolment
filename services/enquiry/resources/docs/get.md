GET
====

Get current enquiry.

    GET api.go.co/enrollment/enquiry/LO_ID/EMAIL

## Params

- re_enquiry < 0|1: Default is 0.
    - 1: Hide a enquiry on current course, only work if the learner has completed the `course`.

- Response:
    - 404 If not found
    - 200 If found - Return Enquiry RO object
        - id < int
        - source_id < int: LO id
        - target_id < int: Student user id
        - data      < json string: enquiry detail data
            - course    < string: LO title
            - first     < string: Student firstName
            - last      < string: Student lastName
            - mail      < string: Student mail
            - phone     < string: Student phone nbr
            - body      < string: Enquiry message
            - status    < string: 'Accepted' | 'Rejected'
            - created   < string: timestamp
            - updated   < string: timestamp
            - updated_by < string: Manager mail
