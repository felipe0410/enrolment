# Plan create

### Create plan to user
##### Requests

    POST api.go1.co/enrolment/plan/INSTANCE_ID/LO_ID/user/USER_ID=self?jwt=JWT -d {
        status: INT
        due_date: DATETIME
        notify: BOOL
        data: {
            "note": STRING
        }
        version: INT
        source_type: STRING
        source_id: INT
        assigner_id: INT
    }

##### Summary
1. non-learners (unassigned & un-enroll): users will have a new assignment (new plan)
   - new assignment email
2. self-directed (enrolled but unassigned): users will have a new assignment link with current enrolment, current enrolment will not be updated
   - new assignment email

If version == 2:
- assigned (regardless of enrolment): users will be re-assign
   - Not started: archived old plan, and a new plan will be created
   - In progress / Completed : enrolment will be archived, archived old plan, and a new plan will be created
   - re-assignment email
   
##### Parameters
| Name | Located in | Description | Required | Schema |
| ---- | ---------- | ----------- | -------- | ------ |
| INSTANCE_ID | path | Go1 portal ID | &check;  | string |
| LO_ID | path | Go1 learning object ID | &check; | string |
| USER_ID | path | Go1 user ID | &check; | string |

##### Payload
| Name | Description | Required | Schema |
| ---- | ----------- | -------- | ------ |
| status | PlanStatuses::ASSIGNED | &check; | integer |
| due_date | Due date for learning, can be optionally but it is required for version = 2 | &check; | datetime |
| notify | Flag for send mail, default true | &cross; | bool |
| data | <pre lang="json">{<br>  "note": "string"<br>}</pre> | &cross; | object |
| version | endpoint version | &cross; | 0/2 |
| source_type | Source of assign. Require Staff JWT . Eg: group, etc | &cross; | string | 
| source_id | Source ID of assign. Require Staff JWT. Eg: 1234 | &cross; | integer | 
| assigner_id | Assigner ID of assign. Require Staff JWT. Eg: 1234 | &cross; | integer | 

##### Responses
| Code | Description | Schema |
| ---- | ----------- | ------ |
| 200 | OK | [results](#results) |
| 400 | Invalid request | [errors](#errors) |
| 403 | invalid access right | [errors](#errors) |
| 404 | portal/lo/user not found | [errors](#errors) |
| 500 | Internal Server Error | |

### Create plan to group
##### Requests
    POST api.go1.co/enrolment/plan/INSTANCE_ID/LO_ID/group/GROUP_ID=self?jwt=JWT -d {
        status: INT
        due_date: DATETIME
        notify: BOOL
        exclude_self: BOOL|NULL
        data: {
            "note": STRING
        }
    }
    
- exclude_self: Exclude plan assigning for current user

##### Responses
| Code | Description | Schema |
| ---- | ----------- | ------ |
| 200 | OK | [results](#results) |
| 400 | Invalid request | [errors](#errors) |
| 403 | invalid access right | [errors](#errors) |
| 404 | portal/lo/user not found | [errors](#errors) |
| 500 | Internal Server Error | |

### Models

#### errors
| Name | Type | Description | Required |
| ---- | ---- | ----------- | -------- |
| message | string |  | &check; |

#### results
| Name | Type | Description | Required |
| ---- | ---- | ----------- | -------- |
| result | json | <pre lang="json">{<br>  "id": "10"<br>}</pre> |  &check; |
