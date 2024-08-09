Get user learning
====
    GET api.go1.co/enrolment/user-learning/{PORTAL_ID}?userId=USER_ID&contentId=CONTENT_ID&offset=OFFSET&limit=LIMIT&status=STATUS&sort[SORT_FIELD_NAME]=SORT_OPERATION&jwt=JWT
## Params

- PORTAL_ID < integer: Taken portal Id
- USER_ID < integer (optional): Learner user id
- CONTENT_ID < integer (optional): Content Id
- OFFSET < integer (optional): Default is 0
- LIMIT < integer (optional): Default is 20
- STATUS < integer (optional): Default is NULL.
- SORT_FIELD_NAME < string: Allowed values. DEFAULT value is updatedAt
    * startedAt
    * endedAt
    * updatedAt
- SORT_OPERATION < string: Allowed values
    * asc
    * desc

## Response

- 400 If invalid type
- 403 If permission denied.
- 200 If OK - Return courses and standalone learning items enrolment.
    - data: object
        - pageInfo < object
            - hasNextPage < boolean
        - totalCount < int
        - edges < object[]: List of learning plan object
            - id < int
            - status < string
            - endedAt < int: timestamp
            - lo < object
                - id < id
                - title < string
                - label < string: Type of content
                - publisher < object
                    - subDomain < string: Portal domain
        
        
        
