Plan browsing
====

## Browse plan
### Request
    
    GET api.go1.co/enrolment/plan/INSTANCE_ID?jwt=JWT
    
### Params

- INSTANCE_ID < INT
- JWT: Payload must include valid profile ID
- entityId < INT|STRING: If entityId is a string then each value is separated by comma. Example: 123,235
- userId < INT (admin/manager only)
- groupId < INT: filter plan in group
- dueDate < BOOL: filter plans with(out) due date
- sort < STRING: can be id, created_date, due_date. Default is 'id'
- direction < STRING: can be ASC, DESC
- limit < INT
- offset < INT

### Response

- 200 If OK
- 403 if invalid access right
- Plan[]:
    - id < int
    - user_id < int
    - assigner_id < int
    - instance_id < int
    - entity_type < string
    - entity_id < int
    - status < string
    - created_date < string
    
## Browse plan entity
### Request
    
    GET api.go1.co/enrolment/plan-entity/GROUP_ID?jwt=JWT
    
### Params

- GROUP_ID < INT

### Response

- 200 If OK
- 403 if invalid access right
- Entity[]:
    - id < int
    - type < string
