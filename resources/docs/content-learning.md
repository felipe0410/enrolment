Get user learning
====
    GET api.go1.co/enrolment/content-learning/{PORTAL_ID}/{LO_ID}
        ?userIds[]=USER_IDS
        &assignerIds[]=ASSIGNER_IDS
        &offset=OFFSET&limit=LIMIT&status=STATUS&facet=FACET
        &sort[SORT_FIELD_NAME]=SORT_OPERATION&overdue=OVERDUE
        &activityType=ACTIVITY_TYPE
        &startedAt[from]=timestamp
        &startedAt[to]=timestamp
        &endedAt[from]=timestamp
        &endedAt[to]=timestamp
        &assignedAt[from]=timestamp
        &assignedAt[to]=timestamp
        &dueAt[from]=timestamp
        &dueAt[to]=timestamp
        &fields=COLUMN_FIELD_NAME
        &includeInactivePortalAccounts=boolean
        &groupId=integer
        &passed=integer
        &jwt=JWT
## Params

- PORTAL_ID < integer: Taken portal Id
- LO_ID < integer (optional): Content id (course id or singe LI id)
- USER_IDS < integer (optional): User id. Max length of `userIds` is 20.
- ASSIGNER_IDS < integer (optional): User id. Max length of `assignerIds` is 20.
- OFFSET < integer (optional): Default is 0.
- LIMIT < integer (optional): Default is 20. Max is 100.
- STATUS < enum (optional): Default is NULL. Allows values: `not-started`, `in-progress`, `pending`, `completed`, `expired`
- ACTIVITY_TYPE < enum (optional): Default is NULL. Allows values: `assigned`, `self-directed`
- OVERDUE < boolean (optional): Default is NULL. Return only overdue records
- FACET < boolean (optional):  Default is NULL. Return facet which contains statistics information about the content
- SORT_FIELD_NAME < string: Allowed values. DEFAULT value is updatedAt
    * startedAt
    * endedAt
    * updatedAt
- SORT_OPERATION < string: Allowed values
    * asc
    * desc
- Datetime range filter: Below is list of date field that supports range filtering. Note `from` value must be less or equal `to` value
    * startedAt: Filter by enrolment start date
        * from < integer (optional): UTC timestamp
        * to < integer (optional): UTC timestamp
    * endedAt: Filter by enrolment end date
        * from < integer (optional): UTC timestamp
        * to < integer (optional): UTC timestamp
    * assignedAt: Filter by assign created date
        * from < integer (optional): UTC timestamp
        * to < integer (optional): UTC timestamp
    * dueAt: Filter by assign due date
        * from < integer (optional): UTC timestamp
        * to < integer (optional): UTC timestamp
- fields < string (optional): When you query a node it returns a set of fields by default. You can specify which fields you want returned by using the `fields` parameter. 
However, supporting: `legacyId`, `state.legacyId`, `user.legacyId` and `user.email`. Example: `fields=legacyId,state.legacyId,user.email`
- includeInactivePortalAccounts < boolean:  Set to `true` to return inactive portal accounts, defaults to false
- groupId < integer (optional): filter content learning by group id
- passed < integer (optional): filter passed or not passed enrolments, require to be sent along with status=completed 

    
## Notes
- GET /enrolment/content-learning/{PORTAL_ID}/{LO_ID} only supports LO_ID of a course or single LI at the moment. Improvement ticket: https://go1web.atlassian.net/browse/PSE-502

## Authorization
- Portal admin and content admin can see all learning in the content
- Manager can see all learning of users that they managed up to 4 hierarchy level.

## Response

- 400 If invalid type
- 403 If permission denied.
- 200 If OK - Return courses and standalone learning items enrolment.
    - data: object
        - pageInfo < object
            - hasNextPage < boolean
        - totalCount < int
        - facet < object
            - total < integer
            - overdue < integer
            - assigned < integer
            - self-directed < integer
            - not-started < integer
            - in-progress < integer
            - not-passed < integer
            - completed
        - edges < object[]: List of learning plan object
            - node < object
              - legacyId < int
              - dueDate
              - createdAt
              - updatedAt
              - activityType < string
              - state < object
                - legacyId < int
                - status < string
                - passed < boolean
                - startedAt < UTC timestamp
                - endedAt < UTC timestamp
                - updatedAt < UTC timestamp
              - user < object
                  - legacyId < int
                  - firstName < string
                  - lastName < string: Type of content
                  - email < string
                  - avatarUri < string
                  - status < bool
                  - account < object: it can be null
                      - legacyId < id
                      - status < bool
