Enrolment
=========

The enrolment service to handle business logic related to learning activity of user

## Maintainers

- Activities and Accounts @aaa-team

## Installation

```sh
git clone git@code.go1.com.au:microservices/enrolment.git
cd enrolment
composer install
```

## Build / Run
```sh

# Sets local env vars
export GO1_DB_HOST=127.0.0.1
export GO1_DB_SLAVE=127.0.0.1
export GO1_DB_NAME=gc_go1
export GO1_DB_USERNAME=root
export GO1_DB_PASSWORD=root
export GO1_DB_PORT=3306

export LO_DB_HOST=127.0.0.1
export LO_DB_SLAVE=127.0.0.1
export LO_DB_NAME=gc_go1
export LO_DB_USERNAME=root
export LO_DB_PASSWORD=root
export LO_DB_PORT=3306
```

**Run**

```sh
cp -r ./vendor/go1/app/public ./public && php -S localhost:8080 -t public public/index.php
```

Checkout at http://localhost:8080

## How to test

### Run unit tests 

```sh
composer install
XDEBUG_MODE=coverage vendor/bin/phpunit -c resources/ci/phpunit.xml --coverage-text --stop-on-failure
```

### Code style
```sh
php-cs-fixer fix --using-cache=no --rules=@PSR12 .
```

## Run integration tests
## Refer to -  https://code.go1.com.au/domain-users/user/-/blob/master/README.md#run-integration-tests-in-desktop

### Get password
```
Get INTEGRATION_STAFF_PASSWORD for staff@go1.co from [Testing Environments](https://go1web.atlassian.net/wiki/spaces/QB/pages/425690809/Testing+Environments)
```

### Set up
```
export COMPOSER=codeception-composer.json
composer install

export INTEGRATION_STAFF_PASSWORD=<get password>
export DATA_RESIDENCY_REGION=<region>
```

### Integration tests
* Run all tests on QA environment: `./vendor/bin/codecept run --env qa`
* Run a specific test case: `./vendor/bin/codecept run integration class_name::test_case_name`
* Run a specific test class: `./vendor/bin/codecept run integration class_name`
* Run tests with reporting:  `./vendor/bin/codecept run --html index.html`
* Run tests with debug mod: `./vendor/bin/codecept run -d`


## Contribute

Create a merge request from JIRA issue branch to master

## Contribution guidelines
Any new code ought to have the following:
 - Low coupling with existing codebase (unless we are changing the existing functionality)
 - It is well tested
 - The types for functions and class properties defined in code + in unit tests where possible
 - It is readable and maintainable
 - It is performant

## Features

1. User can create enrolment on learning pathway.
    a. If the learning pathway is commercial, user will have enrolment to all sub courses.
    b. If the learning pathway is free, user will have to purchase for each inside commercial courses .
2. User can create enrolment on a course.
    a. When the status of course-enrolment is changed, if user has enrolment on parent learning pathway, the progress percentage of learning pathway will be recalculated.
3. User can create enrolment on a module ONLY when user has enrolment on a course
    a. If the enrolment is active, user can get a token.
        - This token is only available in very limited time, user a fetch again if it's expired.
        - From the token, user can load details of learning items inside the module.
    b. When the status of module-enrolment is changed
        - The progress percentage of course will be recalculated.
4. Use can create enrolment on any learning item.
    a. When the status of LI-enrolment is changed, if user has enrolment on parent module, the progress percentage of module will be recalculated.
5. Portal admin can create enrolment for student on their behalf.
6. Supporting payment gateways:
    a. Stripe
    b. Credit
7. Service will broadcast on events:
    a. Events: enrolment.{created, updated, deleted}
    b. Event names: enrolment.created, enrolment.updated, enrolment.deleted
8. Event Listeners:
    a. It will listen for enrolment create/update/delete events that have a due date and create a plan object and link a plan object if a due date is provided, these are also created inside of enrolment service if enrolment created/updated via rest
    b. If a plan is created after an enrolment it will be linked via gc_enrolment_plan table 
    c. Create assigned plan when user assigned to a group
    d. Check for changes in content where LI enrolments have added or removed a parent_lo_id
9. Staff API:
    a. On a post /staff/merge/{portalId}/{from}/{to} , it will merge enrolments for two accounts together
    b. On a post /staff/fix/{id}, it will fix enrolment parent_enrolment_id
    c. On a post /staff/fix-isolated-lo-plans/{offset}, it will add in missing gc_enrolment_plan records

## Documentation
- Check the [resources/docs folder](resources/docs)
- Plan service - follow the docs in [services/plan/resource/docs folder](services/plan/resources/docs)
