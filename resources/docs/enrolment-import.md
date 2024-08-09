[Deprecated] Enrolment import
====

This endpoint is deprecated. It is replaced by
https://code.go1.com.au/go1-core/learning-record/enrolment-import/blob/master/resources/docs/enrolment-import.yaml

## Request

```
POST api.go1.co/enrolment//lo/{loId}/import-users?jwt=JWT
```

- users < array: user email list
- notify < boolean
- instance < string: membership instance, In case import users for premium course
## Response

- 400 If invalid input.
- 403 If enrolment creation is not allowed.
- 500 If internal error.
- 200 {"id": ENROLMENT_ID}
