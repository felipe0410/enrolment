Plan recurring
===

## Create recurring plan

    POST api.go1.co/enrolment/plan/{planId}/recurring?jwt=JWT -d {
       recurring_id: [INT] // the recurring id that the manager/admin intend to set the recurring setting for the plan
    }

### Flow of Recurring
1. validating Input & loading data
   ```
   Only addmin, Manager or original assigner can edit the assignment
   ```
2. Delete existing recurring_plan if any
3. Archive recurring if exist
4. Create new recurring plan

### Response

- 204 If created
- 400 If invalid request
- 403 If invalid access right
- 404 If plan|portal|lo|user not found
- 500 If runtime error

### API Docs
https://code.go1.com.au/domain-learning-activity/enrolment/-/blob/master/services/recurring/resources/docs/recurring.openapi.yaml
