<?php

declare(strict_types=1);

namespace Keboola\App\OrchestratorMigrate\Tests;

use PHPUnit\Framework\TestCase;
use Keboola\App\OrchestratorMigrate\ConfigDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigDefinitionTest extends TestCase
{
    /**
     * @dataProvider provideValidConfigs
     */
    public function testValidConfigDefinition(array $inputConfig, array $expectedConfig): void
    {
        $definition = new ConfigDefinition();
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration($definition, [$inputConfig]);
        $this->assertSame($expectedConfig, $processedConfig);
    }

    /**
     * @return mixed[][]
     */
    public function provideValidConfigs(): array
    {
        return [
            'config' => [
                [
                    'parameters' => [
                        '#kbcToken' => 'some-token',
                        'kbcUrl' => 'https://connection.keboola.com',
                    ],
                ],
                [
                    'parameters' => [
                        '#kbcToken' => 'some-token',
                        'kbcUrl' => 'https://connection.keboola.com',
                    ],
                ],
            ],
            'config without KBC url' => [
                [
                    'parameters' => [
                        '#kbcToken' => 'some-token',
                    ],
                ],
                [
                    'parameters' => [
                        '#kbcToken' => 'some-token',
                    ],
                ],
            ],
            'config with extra params' => [
                [
                    'parameters' => [
                        '#kbcToken' => 'some-token',
                        'kbcUrl' => 'https://connection.keboola.com',
                        'other' => 'something',
                    ],
                ],
                [
                    'parameters' => [
                        '#kbcToken' => 'some-token',
                        'kbcUrl' => 'https://connection.keboola.com',
                        'other' => 'something',
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidConfigs
     */
    public function testInvalidConfigDefinition(
        array $inputConfig,
        string $expectedExceptionClass,
        string $expectedExceptionMessage
    ): void {
        $definition = new ConfigDefinition();
        $processor = new Processor();
        $this->expectException($expectedExceptionClass);
        $this->expectExceptionMessage($expectedExceptionMessage);
        $processor->processConfiguration($definition, [$inputConfig]);
    }

    /**
     * @return mixed[][]
     */
    public function provideInvalidConfigs(): array
    {
        return [
            'empty parameters' => [
                [
                    'parameters' => [],
                ],
                InvalidConfigurationException::class,
                'The child node "#kbcToken" at path "root.parameters" must be configured.',
            ],
            'missing token' => [
                [
                    'parameters' => [
                        'kbcUrl' => 'https://connection.keboola.com',
                    ],
                ],
                InvalidConfigurationException::class,
                'The child node "#kbcToken" at path "root.parameters" must be configured.',
            ],
        ];
    }
}
