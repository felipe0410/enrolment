Manual record
====

## Create

    POST api.go1.co/enrolment/manual-record/INSTANCE/ENTITY_TYPE/ENTITY_ID?jwt=JWT

## Update

    PUT api.go1.co/enrolment/manual-record/ID?jwt=JWT_OF_USER_OR_PORTAL_ADMIN -d {
        "entity_type": STRING
        "entity_id": STRING,
        "data": OBJECT
    }

## Verify

    PUT api.go1.co/enrolment/manual-record/ID/verify/VALUE?jwt=JWT_OF_PORTAL_ADMIN

## DELETE

    DELETE api.go1.co/enrolment/manual-record/ID?jwt=JWT_OF_USER_OR_PORTAL_ADMIN

## Load

    GET api.mygo1.com/v3/enrolment-servce/manual-record/INSTANCE/lo/LO_ID?jwt=JWT
    
        {
			id:          INT
			instance_id: INT
			entity_type: STRING (lo, award, url, workshop)
			entity_id:   STRING	(http://xyz.com/dsafafdg/asdfdsfgsgf/)
			user_id:     INT
			verified:    BOOL
			created:     INT
			updated:     INT
			data: NULL || {
				title:       STRING
				description: STRING
				verify:      {
					user_id:  INT
					note:     STRING
					created:  INT
					updated:  INT
				}
			}
        }

## Browsing

    GET api.go1.co/enrolment/manual-record/INSTANCE/USER_ID<INT|me>/LIMIT/OFFSET?jwt=JWT
        &verifield=all<DEFAULT >|true|false
        &entityType=STRING # default = lo
        &entityId=INT

    [
        {
			id:          INT
			instance_id: INT
			entity_type: STRING (lo, award, url, workshop)
			entity_id:   STRING	(http://xyz.com/dsafafdg/asdfdsfgsgf/)
			user_id:     INT
			verified:    BOOL
			created:     INT
			updated:     INT
			data: NULL || {
				title:       STRING
				description: STRING
				verify:      {
					user_id:  INT
					note:     STRING
					created:  INT
					updated:  INT
				}
			}
        }
    ]
