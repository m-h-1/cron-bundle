[![CI](https://github.com/m-h-1/cron-bundle/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/m-h-1/cron-bundle/actions/workflows/ci.yml)
![coverage](./docs/coverage_badge.svg)

# cron-bundle

Simple Symfony bundle to schedule commands. Jobs run in parallel. Commands that extend
`AbstractCronCommand` use [Symfony Lock](https://symfony.com/doc/current/components/lock.html)
to prevent overlapping executions of that command.

## TOC

* [Usage](#usage)
  * [Register new job](#register-new-job)
  * [Execute jobs](#execute-jobs)
  * [Stalled job executions](#stalled-job-executions)
* [Installation](#installation)
  * [Step 1: Download the bundle](#step-1-download-the-bundle)
  * [Step 2: Enable the bundle](#step-2-enable-the-bundle)
  * [Step 3: Create DB tables](#step-3-create-db-tables)
  * [Step 4: Add to crontab](#step-4-add-to-crontab)
* [Upgrading an existing installation](#upgrading-an-existing-installation)
* [Config](#config)
* [Local tests with Docker](#local-tests-with-docker)


## Usage

### Register new job

Simply register a new job by creating a new entry in `mh1_cron_job`.

```sql
INSERT INTO mh1_cron_job (id, title, description, command, enabled, schedule, execute_stalled)
VALUES ('d63a6a70-0b56-4b0d-bdfb-06e4c7e7a2eb', 'Symfony Help Command', 'display the Symfony help', 'help', 1, '*/15 * * * *', 1);
```
|FieldName|Description|
|---------|-----------|
|`id`|uuidv4|
|`title`|Title of the command|
|`description`|Optional description|
|`command`|Executable Symfony command name|
|`enabled`|TRUE/1 if job should be enabled for execution, otherwise FALSE/0|
|`schedule`|Crontab style schedule [help](https://crontab.guru/)|
|`execute_stalled`|[see](#stalled-job-executions)|

### Execute jobs

```console
$ bin/console mh1:cron:run
```

With the Doctrine runner, each scheduled minute is reserved in the database before its child process
starts. The reservation survives failed runs, interrupted runners and deletion of individual reports.
The runner requires an auto-commit connection for scheduled runs. Calls to `execute($jobs)` with an
explicit list remain manual runs.

The runner prints job failures and continues with the other jobs. Use `bin/console mh1:cron:run --strict`
to return a non-zero status if any job fails. Invalid schedules appear in the command output. Process
timeouts are enforced during polling and recorded in the report with exit code `124`. The default
process timeout is 60 seconds.

### Stalled job executions

With `execute_stalled` enabled, the Doctrine runner catches up once for the latest missed occurrence.
For example, if an hourly job last ran at 08:00 and the runner comes back at 11:15, it runs the
11:00 occurrence. If the runner comes back during a regularly scheduled minute, it runs that
occurrence. Disabled jobs wait until re-enabled.

The runner uses the last reserved scheduled minute regardless of how long that execution took or
how it ended. For jobs with older reports but no reservations, it uses the most recent completed
report's start time. A job without a reservation or completed report starts at its first regular
occurrence. Jobs with `execute_stalled` disabled only run in their regular scheduled minutes.

Cron expressions use `execution_time_zone`. Report times are written and read in UTC, and two
occurrences in a repeated local hour have distinct UTC reservations.

## Installation

### Step 1: Download the Bundle

```console
$ composer require m-h-1/cron-bundle
```

### Step 2: Enable the Bundle

```php
// config/bundles.php

return [
    // ...
    MH1\CronBundle\MH1CronBundle::class => ['all' => true],
];
```

### Step 3: Create DB Tables

Create tables for the Doctrine entities:

#### DoctrineMigrations

```console
$ bin/console doctrine:migrations:diff
$ bin/console doctrine:migrations:migrate
```

#### raw SQL

```sql
CREATE TABLE mh1_cron_job
(
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    description     VARCHAR(255) NULL,
    command         VARCHAR(255) NOT NULL,
    enabled         TINYINT(1)   NOT NULL,
    schedule        VARCHAR(255) NOT NULL,
    execute_stalled TINYINT(1)   NOT NULL
) COLLATE = utf8mb4_unicode_ci;

CREATE TABLE mh1_cron_job_report
(
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cron_job_id CHAR(36) NOT NULL,
    start_time  DATETIME NOT NULL,
    end_time    DATETIME NULL,
    exit_code   INT      NULL,
    output      LONGTEXT NULL,
    duration    INT      NULL,
    CONSTRAINT fk_a297993479099ed8 FOREIGN KEY (cron_job_id) REFERENCES mh1_cron_job (id)
) COLLATE = utf8mb4_unicode_ci;

CREATE INDEX idx_a297993479099ed8 ON mh1_cron_job_report (cron_job_id);

CREATE TABLE mh1_cron_job_slot
(
    cron_job_id      CHAR(36) NOT NULL,
    scheduled_minute INT      NOT NULL,
    PRIMARY KEY (cron_job_id, scheduled_minute),
    FOREIGN KEY (cron_job_id) REFERENCES mh1_cron_job (id) ON DELETE CASCADE
) COLLATE = utf8mb4_unicode_ci;
```

### Step 4: Add to crontab

Add the command to crontab and replace PATH_TO_APPLICATION with the path to your Symfony project directory.

```console
* * * * *   PATH_TO_APPLICATION/bin/console mh1:cron:run
```

## Upgrading an existing installation

Generate and apply a Doctrine migration before running the new scheduler. The migration adds
`mh1_cron_job_slot`, which uses the existing job ID and the number of minutes since the Unix epoch
as its primary key. Existing report timestamps are interpreted as UTC, as written by the bundle's
logger. Installations that used custom writers to store local clock times need to convert those values
to UTC using the time zone they were written in. Installations that use raw MySQL SQL can add the
table using the definition above.

## Config

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    service: null # override job service with a custom service
    log_service: null # override logging service
    check_interval: 1000 # milliseconds to wait between the checks if a process is running (must be greater than 1)
    execution_time_zone: null # use a custom time zone for job scheduling, the default is the PHP default timezone
    lock_prefix: '' # use a prefix for cronjob logging, the default is empty string
    php_executable_path: null # use a custom path to php executable. e.g. /usr/local/php/bin/php
```

#### Custom job service

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    service: App\Service\CustomCronJobService
```

#### Different logging service

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    log_service: App\Service\CustomLogService
```


#### Wait half a second (instead of one second) between the checkRunning calls

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    check_interval: 500
```

#### Use a custom time zone to check for due time

Every PHP supported timezone string is valid https://www.php.net/manual/en/timezones.php

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    execution_time_zone: 'UTC'
```
```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    execution_time_zone: 'Europe/Berlin'
```

#### Prefix lock name

The symfony lock component uses the commands name (`app:run`) as the name for the lock.

If you want to run the same command in different deployments or folders on the same system you have to use 
this parameter to prefix the name of the locks, e.g. `instance1:app:run`, `instance2:app:run`.

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    lock_prefix: 'second_instance'
```

#### Use a custom path to php executable

```yaml
# config/packages/mh1_cron.yaml
mh1_cron:
    php_executable_path: '/usr/local/php/bin/php'
```

## Local tests with Docker

Use Docker Compose to build the test runner and run the test suite. Each run resolves Composer
dependencies inside a fresh container for the selected PHP version. The host's `vendor/` and
`composer.lock` are separate from the container.

```sh
docker compose run --build --rm test                # PHP 8.2 by default
PHP_VERSION=8.5 docker compose run --build --rm test
```

To test the PHP 8.2–8.6 releases in sequence, stopping on the first failure, run the following.

```sh
(
  for version in 8.2 8.3 8.4 8.5 8.6; do
    PHP_VERSION="$version" docker compose run --build --rm test || exit 1
  done
)
```

To reproduce the CI check with the lowest dependency versions on PHP 8.2, run the following.
Deprecations from the bundle and direct calls to deprecated APIs fail the check.

```sh
PHP_VERSION=8.2 docker compose run --build --rm \
  -e 'SYMFONY_DEPRECATIONS_HELPER=max[self]=0&max[direct]=0&quiet[]=indirect' \
  test sh -ec 'composer update --prefer-lowest --no-interaction --no-progress && composer run-script test'
```
