Enrolment Revision
====

# Create revision
 This endpoint allows Go1 user to create an enrolment revision for an archived enrolment

## Example
```
POST api.go1.co/enrolment/revision?jwt=GO1_STAFF_JWT
{
    enrolment_id: 123131
    start_date: 1546300800
    end_date: 1546300800
    status: in-progress
    result: 0
    pass: 0
    data: {
        actor_user_id: 12321
        app: achievement
    }
}

```
    
## Params

- enrolment_id < integer: the enrolment id we create revision for
- start_date < integer: the start date of this revision in `unix time`
- end_date < integer: the end date of this revision in `unix time`
- status < string: The revision status, default is 'in-progress'
- result < integer: The revision result, default is 0
- pass < integer: The revision pass, allow values 
- data < object: The optional data to keep track this revision
    - actor_user_id
    - app

## Response

- 400 If invalid type
- 403 If permission denied.
- 201 If OK - Return Enrolment revision object
    - id < int
    - lo_id < int
    - lo_type < string
    - taken_instance_id < int
    - profile_id < string
    - status < string
