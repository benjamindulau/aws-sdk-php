<?php
/**
 * Copyright 2010-2013 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 * http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */

namespace Aws\Common\Iterator;

use Aws\Common\Enum\UaString as Ua;
use Aws\Common\Exception\RuntimeException;
use Guzzle\Iterator\FilterIterator;
use Guzzle\Iterator\MapIterator;
Use Guzzle\Service\Resource\Model;
use Guzzle\Service\Resource\ResourceIterator;

/**
 * Iterate over a client command
 */
class AwsResourceIterator extends ResourceIterator
{
    const INPUT_TOKEN  = 'input_token';  // Formerly "token_param"
    const OUTPUT_TOKEN = 'output_token'; // Formerly "token_key"
    const LIMIT_KEY    = 'limit_key';    // Formerly "limit_param" in some places
    const RESULT_KEY   = 'result_key';
    const MORE_RESULTS = 'more_results'; // Formerly "more_key"

    /**
     * @var Model Result of a command
     */
    protected $lastResult = null;

    /**
     * @param callable $callback The callback to apply as a map function
     *
     * @return MapIterator
     */
    public function map($callback)
    {
        return new MapIterator($this, $callback);
    }

    /**
     * @param callable $callback The callback to apply as a filter function
     *
     * @return FilterIterator
     */
    public function filter($callback)
    {
        return new FilterIterator($this, $callback);
    }

    /**
     * Provides access to the most recent result obtained by the iterator.
     *
     * @return Model|null
     */
    public function getLastResult()
    {
        return $this->lastResult;
    }

    /**
     * {@inheritdoc}
     * This AWS specific version of the resource iterator provides a default implementation of the typical AWS iterator
     * process. It relies on configuration and extension to implement the operation-specific logic of handling results
     * and nextTokens. This method will loop until resources are acquired or there are no more iterations available.
     */
    protected function sendRequest()
    {
        do {
            // Prepare the request including setting the next token
            $this->prepareRequest();
            if ($this->nextToken) {
                $this->applyNextToken();
            }

            // Execute the request and handle the results
            $this->command->add(Ua::OPTION, Ua::ITERATOR);
            $this->lastResult = $this->command->getResult();
            $resources = $this->handleResults($this->lastResult);
            $this->determineNextToken($this->lastResult);

            // If no resources collected, prepare to reiterate before yielding
            if ($reiterate = empty($resources) && $this->nextToken) {
                $this->command = clone $this->originalCommand;
            }
        } while ($reiterate);

        return $resources;
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareRequest()
    {
        // Get the limit parameter key to set
        $limitKey = $this->get(self::LIMIT_KEY);
        if ($limitKey && ($limit = $this->command->get($limitKey))) {
            $pageSize = $this->calculatePageSize();

            // If the limit of the command is different than the pageSize of the iterator, use the smaller value
            if ($limit && $pageSize) {
                $realLimit = min($limit, $pageSize);
                $this->command->set($limitKey, $realLimit);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function handleResults(Model $result)
    {
        $results = array();

        // Get the result key that contains the results
        if ($resultKey = $this->get(self::RESULT_KEY)) {
            $results = $result->getPath($resultKey) ?: array();
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    protected function applyNextToken()
    {
        // Get the token parameter key to set
        if ($tokenParam = $this->get(self::INPUT_TOKEN)) {
            // Set the next token. Works with multi-value tokens
            if (is_array($tokenParam)) {
                if (is_array($this->nextToken) && count($tokenParam) === count($this->nextToken)) {
                    foreach (array_combine($tokenParam, $this->nextToken) as $param => $token) {
                        $this->command->set($param, $token);
                    }
                } else {
                    throw new RuntimeException('The definition of the iterator\'s token parameter and the actual token '
                        . 'value are not compatible.');
                }
            } else {
                $this->command->set($tokenParam, $this->nextToken);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function determineNextToken(Model $result)
    {
        $this->nextToken = null;

        // If the value of "more key" is true or there is no "more key" to check, then try to get the next token
        $moreKey = $this->get(self::MORE_RESULTS);
        if ($moreKey === null || $result->getPath($moreKey)) {
            // Get the token key to check
            if ($tokenKey = $this->get(self::OUTPUT_TOKEN)) {
                // Get the next token's value. Works with multi-value tokens
                $getToken = function ($key) use ($result) {
                    return $result->getPath((string) $key);
                };
                $this->nextToken = is_array($tokenKey) ? array_map($getToken, $tokenKey) : $getToken($tokenKey);
            }
        }
    }
}
