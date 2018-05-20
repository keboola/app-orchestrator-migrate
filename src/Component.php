<?php

declare(strict_types=1);

namespace Keboola\App\OrchestratorMigrate;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\Orchestrator\Client as OrchestratorClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Component extends BaseComponent
{
    public const ORCHESTRATOR_COMPONENT_ID = 'orchestrator';

    /**
     * @var OrchestratorClient
     */
    private $sourceClient;

    /**
     * @var OrchestratorClient
     */
    private $destinationClient;

    /**
     * @var Logger
     */
    private $logger;

    public function run(): void
    {
        $this->initLogger();
        $this->initOrchestratorClients();

        $this->logger->info('Loading orchestrations from current project');
        $sourceOrchestrations = $this->sourceClient->getOrchestrations();

        if (!count($sourceOrchestrations)) {
            $this->logger->info('Current project contains any orchestrations');
            return;
        }

        $this->checkDestinationProject();

        $sourceDestinationIds = [];
        $destinationIdsForConvert = [];

        // migrate orchestrations
        $this->logger->info('Orchestrations migration');
        foreach ($sourceOrchestrations as $i => $sourceOrchestration) {
            $this->logger->info(sprintf('Orchestration (%d/%d)', $i + 1, count($sourceOrchestrations)));

            $sourceId = $sourceOrchestration['id'];
            $orchestration = $this->sourceClient->getOrchestration($sourceId);

            $destinationId = $this->migrateOrchestration($orchestration);
            $sourceDestinationIds[$sourceId] = $destinationId;

            if ($this->hasOrchestratorInTasks($orchestration)) {
                $destinationIdsForConvert[] = $destinationId;
            }
        }

        // fix orchestrations IDs in tasks
        if (count($destinationIdsForConvert)) {
            $this->logger->info('Orchestrations tasks fix');
            foreach ($destinationIdsForConvert as $i => $destinationId) {
                $this->logger->info(sprintf('Orchestration (%d/%d)', $i + 1, count($destinationIdsForConvert)));
                $this->fixDestinationOrchestrationTasks($destinationId, $sourceDestinationIds);
            }
        }
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    private function hasOrchestratorInTasks(array $orchestration): bool
    {
        foreach ($orchestration['tasks'] as $task) {
            if (isset($task['component'])) {
                if ($task['component'] === self::ORCHESTRATOR_COMPONENT_ID) {
                    return true;
                }
            }
        }

        return false;
    }

    private function checkDestinationProject(): void
    {
        $this->logger->info('Checking destination project for existing orchestrations');
        $orchestrations = $this->destinationClient->getOrchestrations();
        if (count($orchestrations)) {
            throw new UserException('Destination project has some existing orchestrations');
        }
    }

    private function fixDestinationOrchestrationTasks(int $orchestrationId, array $idsMap): void
    {
        $orchestration = $this->destinationClient->getOrchestration($orchestrationId);

        $this->logger->info(sprintf('Fixing "%s" orchestration tasks', $orchestration['name']));
        foreach ($orchestration['tasks'] as $key => $task) {
            if (isset($task['component'])) {
                if ($task['component'] !== self::ORCHESTRATOR_COMPONENT_ID) {
                    continue;
                }
            }

            $sourceId = $task['actionParameters']['config'];
            if (isset($idsMap[$sourceId])) {
                $task['actionParameters']['config'] = $idsMap[$sourceId];

                $orchestration['tasks'][$key] = $task;
            }
        }

        $this->destinationClient->updateOrchestration($orchestrationId, ['tasks' => $orchestration['tasks']]);
    }

    private function migrateOrchestration(array $orchestration): int
    {
        $this->logger->info(sprintf('Migrating "%s" orchestration', $orchestration['name']));

        $response = $this->destinationClient->createOrchestration($orchestration['name'], [
            'crontabRecord' => $orchestration['crontabRecord'],
            'notifications' => $orchestration['notifications'],
            'tasks' => $orchestration['tasks'],
            'active' => false,
        ]);

        $orchestrationId = $response['id'];
        $this->destinationClient->updateOrchestration($orchestrationId, ['active' => false]);

        return $orchestrationId;
    }

    private function getOrchestratorApiUrl(string $kbcToken, string $kbcUrl): string
    {
        $sapiClient = new StorageApiClient([
            'token' => $kbcToken,
            'url' => $kbcUrl,
        ]);

        $index = $sapiClient->indexAction();
        foreach ($index['components'] as $component) {
            if ($component['id'] !== self::ORCHESTRATOR_COMPONENT_ID) {
                continue;
            }

            return $component['uri'];
        }

        $tokenData = $sapiClient->verifyToken();
        throw new UserException(sprintf('Orchestrator not found in %s region', $tokenData['owner']['region']));
    }

    private function initOrchestratorClients(): void
    {
        // source project
        $this->logger->info('Detecting orchestrator API url for source project');

        $kbcToken = $this->getConfig()->getValue(['parameters', '#sourceKbcToken']);
        $kbcUrl = $this->getConfig()->getValue(['parameters', 'sourceKbcUrl']);

        $this->sourceClient = OrchestratorClient::factory([
            'token' => $kbcToken,
            'url' => $this->getOrchestratorApiUrl($kbcToken, $kbcUrl),
        ]);

        // destination project
        $this->logger->info('Detecting orchestrator API url for destination project');

        $kbcToken = getenv('KBC_TOKEN');
        $kbcUrl = getenv('KBC_URL');

        $this->destinationClient = OrchestratorClient::factory([
            'token' => $kbcToken,
            'url' => $this->getOrchestratorApiUrl($kbcToken, $kbcUrl),
        ]);
    }

    private function initLogger(): void
    {
        $formatter = new LineFormatter("%message%\n");

        $errorHandler = new StreamHandler('php://stderr', Logger::WARNING, false);
        $errorHandler->setFormatter($formatter);

        $handler = new StreamHandler('php://stdout', Logger::INFO);
        $handler->setFormatter($formatter);

        $logger = new Logger(
            getenv('KBC_COMPONENTID')?: 'app-orchestrator-migrate',
            [
                $errorHandler,
                $handler,
            ]
        );

        $this->logger =  $logger;
    }
}
