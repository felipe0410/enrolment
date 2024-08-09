Plan view
====

## View a plan

    GET api.go1.co/enrolment/plans/:planId?jwt=JWT
    
### Response

- 200 If OK
- 403 If invalid access right
- 404 If plan is not found
- 500 If runtime error
- Plan:
    - id < string
    - legacyId < integer
    - dueDate < timestamp
    - createdAt < timestamp
    - updatedAt < timestamp 
    - user < object
      - legacyId < string
      - firstName < string
      - lastName < string
      - email < string
      - avatarUri < string
      - status < bool
    - author < object
      - legacyId < integer
      - firstName < string
      - lastName < string
      - email < string
      - avatarUri < string
      - status < bool
- Example:
  ```json
  {
    "id": "xxxx",
    "legacyId": 1,
    "dueDate": 1554976228,
    "createdAt": 1555062628,
    "updatedAt": 1555062628,
    "user": {
      "legacyId": 33,
      "firstName": "Joe",
      "lastName": "Doe",
      "email": "student@example.com",
      "avatarUri": "\/\/a.png",
      "status": true
    },
    "author": {
      "legacyId": 44,
      "firstName": "Joe",
      "lastName": "Manager",
      "email": "manager@example.com",
      "avatarUri": "\/\/a.png",
      "status": true
    }
}
  ```
