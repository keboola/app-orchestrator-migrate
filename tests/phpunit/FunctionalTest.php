<?php

declare(strict_types=1);

namespace Keboola\App\OrchestratorMigrate\Tests;

use Keboola\App\OrchestratorMigrate\Component;
use Keboola\Orchestrator\Client as OrchestratorClient;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
{
    /**
     * @var Temp
     */
    protected $temp;

    /**
     * @var StorageApi
     */
    protected $sapiClient;

    /**
     * @var OrchestratorClient
     */
    protected $sourceClient;

    /**
     * @var OrchestratorClient
     */
    protected $destinationClient;

    public function setUp(): void
    {
        parent::setUp();

        $this->sapiClient = new StorageApi([
            'token' => getenv('TEST_SOURCE_STORAGE_API_TOKEN'),
            'url' => getenv('TEST_SOURCE_STORAGE_API_URL'),
        ]);

        $this->sourceClient = OrchestratorClient::factory([
            'token' => getenv('TEST_SOURCE_STORAGE_API_TOKEN'),
            'url' => $this->getOrchestratorApiUrl($this->sapiClient),
        ]);

        $this->destinationClient = OrchestratorClient::factory([
            'token' => getenv('TEST_DESTINATION_STORAGE_API_TOKEN'),
            'url' => $this->getOrchestratorApiUrl(new StorageApi([
                'token' => getenv('TEST_DESTINATION_STORAGE_API_TOKEN'),
                'url' => getenv('TEST_DESTINATION_STORAGE_API_URL'),
            ])),
        ]);

        $this->temp = new Temp('app-orchestrator-migrate');
        $this->temp->initRunFolder();

        $this->cleanupKbcProjects();
    }

    public function testSuccessfulRun(): void
    {
        $childId = $this->createChildOrchestration($this->sourceClient);
        $this->createMasterOrchestration($childId, $this->sourceClient);

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'parameters' => [
                    '#kbcToken' => getenv('TEST_DESTINATION_STORAGE_API_TOKEN'),
                    'kbcUrl' => getenv('TEST_DESTINATION_STORAGE_API_URL'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertContains('Detecting orchestrator API', $output);
        $this->assertContains('Orchestrations tasks fix', $output);
        $this->assertContains('Migrating', $output);

        // check destination orchestrations
        $orchestrations = $this->destinationClient->getOrchestrations();

        self::assertCount(2, $orchestrations);

        $masterId = null;
        $childId = null;
        foreach ($orchestrations as $orchestration) {
            if ($orchestration['name'] === 'Master orchestration') {
                $this->validateMasterOrchestration($orchestration['id'], $this->destinationClient);
                $masterId = $orchestration['id'];
            }

            if ($orchestration['name'] === 'Child orchestration') {
                $this->validateChildOrchestration($orchestration['id'], $this->destinationClient);
                $childId = $orchestration['id'];
            }
        }

        self::assertNotEmpty($masterId);
        self::assertNotEmpty($childId);

        // check task fix
        $orchestration = $this->destinationClient->getOrchestration($masterId);
        self::assertEquals($childId, $orchestration['tasks'][0]['actionParameters']['config']);
    }

    public function testNotEmptyProjectErrorRun(): void
    {
        $this->createChildOrchestration($this->sourceClient);
        $this->createChildOrchestration($this->destinationClient);

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'parameters' => [
                    '#kbcToken' => getenv('TEST_DESTINATION_STORAGE_API_TOKEN'),
                    'kbcUrl' => getenv('TEST_DESTINATION_STORAGE_API_URL'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains('Destination project has some existing orchestrations', $runProcess->getOutput());
    }

    private function cleanupKbcProjects(): void
    {
        $orchestrations = $this->sourceClient->getOrchestrations();
        foreach ($orchestrations as $orchestration) {
            $this->sourceClient->deleteOrchestration($orchestration['id']);
        }

        $orchestrations = $this->destinationClient->getOrchestrations();
        foreach ($orchestrations as $orchestration) {
            $this->destinationClient->deleteOrchestration($orchestration['id']);
        }
    }

    private function createTestProcess(): Process
    {
        $runCommand = "php /code/src/run.php";
        return new  Process($runCommand, null, [
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_SOURCE_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_SOURCE_STORAGE_API_TOKEN'),
        ]);
    }

    private function createChildOrchestration(OrchestratorClient $client): int
    {
        $orchestration = $client->createOrchestration('Child orchestration', []);
        $orchestrationId = $orchestration['id'];

        // disable orchestration
        $client->updateOrchestration($orchestrationId, ['active' => false]);

        $this->validateChildOrchestration($orchestrationId, $client);
        return $orchestrationId;
    }

    private function validateChildOrchestration(int $orchestrationId, OrchestratorClient $client): void
    {
        $orchestration = $client->getOrchestration($orchestrationId);

        self::assertEquals('Child orchestration', $orchestration['name']);
        self::assertEmpty($orchestration['crontabRecord']);
        self::assertFalse($orchestration['active']);

        self::assertCount(0, $orchestration['tasks']);
    }

    private function createMasterOrchestration(int $childOrchestrationId, OrchestratorClient $client): int
    {
        $orchestration = $client->createOrchestration(
            'Master orchestration',
            [
                'crontabRecord' => '1 1 1 1 1',
            ]
        );

        $orchestrationId = $orchestration['id'];

        // enable orchestration
        $client->updateOrchestration($orchestrationId, ['active' => true]);

        // create orchestration tasks
        $task1 = (new OrchestrationTask())->setComponent(Component::ORCHESTRATOR_COMPONENT_ID)
            ->setAction('run')
            ->setContinueOnFailure(true)
            ->setPhase(1)
            ->setActive(true)
            ->setTimeoutMinutes(10)
            ->setActionParameters(['config' => $childOrchestrationId]);


        $task2 = (new OrchestrationTask())->setComponent('keboola.csv-import')
            ->setAction('run')
            ->setContinueOnFailure(false)
            ->setPhase(2)
            ->setActive(false)
            ->setTimeoutMinutes(0);

        $client->updateTasks($orchestrationId, [$task1, $task2]);

        $this->validateMasterOrchestration($orchestrationId, $client);
        return $orchestrationId;
    }

    private function validateMasterOrchestration(int $orchestrationId, OrchestratorClient $client): void
    {
        $orchestration = $client->getOrchestration($orchestrationId);

        self::assertEquals('Master orchestration', $orchestration['name']);
        self::assertEquals('1 1 1 1 1', $orchestration['crontabRecord']);

        if ($client === $this->destinationClient) {
            self::assertFalse($orchestration['active']);
        } else {
            self::assertTrue($orchestration['active']);
        }

        self::assertCount(2, $orchestration['tasks']);

        $task = $orchestration['tasks'][0];
        self::assertEquals(Component::ORCHESTRATOR_COMPONENT_ID, $task['component']);
        self::assertEquals('run', $task['action']);
        self::assertTrue($task['continueOnFailure']);
        self::assertEquals(1, $task['phase']);
        self::assertTrue($task['active']);
        self::assertEquals(10, $task['timeoutMinutes']);

        $task = $orchestration['tasks'][1];
        self::assertEquals('keboola.csv-import', $task['component']);
        self::assertEquals('run', $task['action']);
        self::assertFalse($task['continueOnFailure']);
        self::assertEquals(2, $task['phase']);
        self::assertFalse($task['active']);
        self::assertEmpty($task['timeoutMinutes']);
    }

    private function getOrchestratorApiUrl(StorageApi $sapiClient): string
    {
        $index = $sapiClient->indexAction();

        foreach ($index['components'] as $component) {
            if ($component['id'] !== Component::ORCHESTRATOR_COMPONENT_ID) {
                continue;
            }

            return $component['uri'];
        }

        $tokenData = $sapiClient->verifyToken();
        throw new \Exception(sprintf('Orchestrator not found in %s region', $tokenData['owner']['region']));
    }
}
