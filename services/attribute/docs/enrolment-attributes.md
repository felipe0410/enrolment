POST
====

Create new enrolment attribute.

    POST api.go1.co/enrolment/enrolment/ENROLMENT_ID/attributes?jwt=JWT
        -d {
            "provider": STRING,
            "type": STRING,
            "url": STRING,
            "description": STRING,
            "documents": [
                { name : STRING, size : INTEGER, type : STRING, url : STRING },
                { name : STRING, size : INTEGER, type : STRING, url : STRING }
            ],
            "award_required": [
                { 
                    goal_id : INTEGER, 
                    value : STRING, 
                    requirements : [{
                        goal_id : INTEGER, 
                        value : STRING
                    }]
                },
                { goal_id : INTEGER, value : STRING },
            ],
            "award_achieved": [
                { 
                    goal_id : INTEGER, 
                    value : STRING, 
                    requirements : [{
                        goal_id : INTEGER, 
                        value : STRING
                    }]
                },
                { goal_id : INTEGER, value : STRING }
            ]
        }

- ENROLMENT_ID < int: ID of enrolment.
- JWT < string: Token of authority.
- Response:
    - 201 If OK
    - 403 If missing or invalid jwt
    - 400 If invalid data
    - 500 Internal error

## Post example:

    POST api.go1.co/enrolment/enrolment/3816660/attributes?jwt=JWT -d '{
        "provider": "GO1",
        "type": "event",
        "url": "http://example.com",
        "description": "Description",
        "documents": [
            {
                "name": "document-external-record",
                "size": 30,
                "type": "document",
                "url": "http://example.com"
            }
        ]
    }'
    
    [
        {
            "id": 1,
            "key": "provider",
            "value": "GO1"
        },
        {
            "id": 2,
            "key": "type",
            "value": "event"
        },
        {
            "id": 3,
            "key": "url",
            "value": "http://example.com"
        },
        {
            "id": 4,
            "key": "description",
            "value": "Description"
        },
        {
            "id": 5,
            "key": "document",
            "value": [
                {
                    "name": "document-external-record",
                    "size": 30,
                    "type": "document",
                    "url": "http://example.com"
                }
            ]
        }
    ]

PUT
====

Update enrolment attribute.

    PUT api.go1.co/enrolment/enrolment/ENROLMENT_ID/attributes?jwt=JWT
        -d {
            "provider": STRING,
            "type": STRING,
            "url": STRING,
            "description": STRING,
            "documents": [
                { name : STRING, size : INTEGER, type : STRING, url : STRING },
                { name : STRING, size : INTEGER, type : STRING, url : STRING }
            ],
            "award_required": [
                { 
                    goal_id : INTEGER, 
                    value : STRING, 
                    requirements : [{
                        goal_id : INTEGER, 
                        value : STRING
                    }]
                },
                { goal_id : INTEGER, value : STRING }
            ],
            "award_achieved": [
                { 
                    goal_id : INTEGER, 
                    value : STRING, 
                    requirements : [{
                        goal_id : INTEGER, 
                        value : STRING
                    }]
                },
                { goal_id : INTEGER, value : STRING }
            ]
        }

- ENROLMENT_ID < int: ID of enrolment.
- JWT < string: Token of authority.
- Response:
    - 204 If OK
    - 403 If missing or invalid jwt
    - 400 If invalid data
    - 500 Internal error
