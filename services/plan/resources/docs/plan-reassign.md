Plan re-assign
===

## Create re-assign plan to user

    POST api.go1.co/enrolment/plan/re-assign?jwt=JWT -d {
       plan_ids: NULL|INT // The list of plan_ids needs to be archived and only support a single value for now. In the future, we will support multiple values
       lo_id: NULL|INT
       user_id: NULL|INT
       portal_id: NULL|INT
       due_date: NULL|INT(unix timestamp)
       reassign_date: NULL|INT(unix timestamp)
       notify: BOOL // default true
       assigner_user_id: NULL|INT
    }

* due_date: unix timestamp which is a future timestamp. It can be null.
* reassign_date: unix timestamp and can be in the past (support past assignment). It can be null.
* assigner_user_id: the user ID of the person who assigns content to the learner. It can be null.
  * If you do not include an `assigner_user_id`, it will be set to the user ID of the current JWT.

### Flow of Reassign
1. If the payload only contain plan_ids, not lo_id
- validating Input & loading data
```
   Only addmin, Manager or original assigner can edit the assignment
```
- Archive existing plan
- Archive enrolment if exist
- Create new plan

2. If the payload only contain lo_id, not plan_ids
- validating Input & loading data
```
   Only Accounts Admin can edit the assignment
```
- Need to due_date and reassign_date (make it able to reassign in the past day)
- proceed with reassign by lo_id
3. If the payload contain both plan_ids and lo_id
- return 400 bad request

### Response

- 201 If created
- 400 If invalid request
- 403 If invalid access right
- 404 If plan|portal|lo|user not found
- 500 If runtime error
- Example:
```json
  [{
    "id": "xxxx" 
  }]
```
