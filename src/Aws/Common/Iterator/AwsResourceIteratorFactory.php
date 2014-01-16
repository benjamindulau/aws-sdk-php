<?php

namespace Aws\Common\Iterator;

use Aws\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Collection;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Resource\ResourceIteratorFactoryInterface;

/**
 * Resource iterator factory used to instantiate the default AWS resource iterator with the correct configuration or
 * use a concrete iterator class if one exists
 */
class AwsResourceIteratorFactory implements ResourceIteratorFactoryInterface
{
    /**
     * @var array Default configuration values for iterators
     */
    protected static $defaultIteratorConfig = array(
        AwsResourceIterator::INPUT_TOKEN  => null,
        AwsResourceIterator::OUTPUT_TOKEN => null,
        AwsResourceIterator::LIMIT_KEY    => null,
        AwsResourceIterator::RESULT_KEY   => null,
        AwsResourceIterator::MORE_RESULTS => null,
    );

    /**
     * @var array Iterator configuration for each iterable operation
     */
    protected $config;

    /**
     * @var ResourceIteratorFactoryInterface Another factory that will be used first to instantiate the iterator
     */
    protected $primaryIteratorFactory;

    /**
     * @param array                            $config                 An array of configuration values for the factory
     * @param ResourceIteratorFactoryInterface $primaryIteratorFactory Another factory to use for chain of command
     */
    public function __construct(array $config, ResourceIteratorFactoryInterface $primaryIteratorFactory = null)
    {
        $this->primaryIteratorFactory = $primaryIteratorFactory;
        $this->config = array();
        foreach ($config as $name => $operation) {
            $this->config[$name] = $operation + self::$defaultIteratorConfig;
        }
    }

    public function build(CommandInterface $command, array $options = array())
    {
        // Get the configuration data for the command
        $commandName = $command->getName();
        $commandSupported = isset($this->config[$commandName]);
        $options += $commandSupported ? $this->config[$commandName] : array();

        // Instantiate the iterator using the primary factory (if one was provided)
        if ($this->primaryIteratorFactory && $this->primaryIteratorFactory->canBuild($command)) {
            $iterator = $this->primaryIteratorFactory->build($command, $options);
        } elseif (!$commandSupported) {
            throw new InvalidArgumentException("Iterator was not found for {$commandName}.");
        } else {
            // Instantiate a generic AWS resource iterator
            $iterator = new AwsResourceIterator($command, $options);
        }

        return $iterator;
    }

    public function canBuild(CommandInterface $command)
    {
        if ($this->primaryIteratorFactory) {
            return $this->primaryIteratorFactory->canBuild($command);
        } else {
            return isset($this->config[$command->getName()]);
        }
    }
}
