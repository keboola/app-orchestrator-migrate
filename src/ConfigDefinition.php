<?php

declare(strict_types=1);

namespace Keboola\App\OrchestratorMigrate;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->children()
                ->scalarNode('#kbcToken')
                    ->isRequired()
                ->end()
                ->scalarNode('kbcUrl')
                ->end()
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }
}
