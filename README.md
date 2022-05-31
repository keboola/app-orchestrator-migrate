# Project Migration - Migrate Orchestrations

[![Build Status](https://travis-ci.com/keboola/app-orchestrator-migrate.svg?branch=master)](https://travis-ci.com/keboola/app-orchestrator-migrate)

> You can use [Project Migrate](https://github.com/keboola/app-project-migrate) application which orchestrates whole process of KBC project migration from one KBC stack to another.

Application migrates Orchestrations between two Keboola Connection projects.

You must **run** this application **in destination project** and the project must contains **any existing orchestrations**.

 ### Application flow

1. Validates if current project has any orchestrations configured
2. Creates orchestrations with:
    - New KBC Storage API token with permissions to all storage buckets and configurations
    - Crontab record, tasks and notifications
3. Fixes IDs of orchestrations configured in tasks _(child orchestrations)_

**All orchestrations are automatically disabled!**

# Usage

Use `#sourceKbcToken` and `sourceKbcUrl` parameters to create asynchronous job.

- `#sourceKbcToken` -  Source project KBC Storage API token
- `sourceKbcUrl` -  KBC Storage API endopint for source project region

```
curl -X POST \
  https://docker-runner.keboola.com/docker/keboola.app-orchestrator-migrate/run \
  -H 'Cache-Control: no-cache' \
  -H 'X-StorageApi-Token: **STORAGE_API_TOKEN**' \
  -d '{
	"configData": {
		"parameters": {
			"#sourceKbcToken": "**SOURCE_PROJECT_KBC_TOKEN**",
			"sourceKbcUrl": "**SOURCE_PROJECT_KBC_URL**"
		}
	}
}'
```


## Development
 
- Clone this repository:

```
git clone https://github.com/keboola/app-orchestrator-migrate.git
cd app-orchestrator-migrate
```

- Create `.env` file an fill variables:
    
```
TEST_SOURCE_STORAGE_API_TOKEN=
TEST_SOURCE_STORAGE_API_URL=

TEST_DESTINATION_STORAGE_API_TOKEN=
TEST_DESTINATION_STORAGE_API_URL=
```

- Build Docker image

```
docker-compose build
```

- Run the test suite using this command

    **Tests will delete all configured orchestrations in both KBC projects!**

```
docker-compose run --rm dev composer ci
```
 
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 

## License

MIT licensed, see [LICENSE](./LICENSE) file.
