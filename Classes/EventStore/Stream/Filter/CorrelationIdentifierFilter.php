<?php
namespace Neos\EventSourcing\EventStore\Stream\Filter;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\EventSourcing\Exception;

/**
 * Correlation identifier filter
 */
class CorrelationIdentifierFilter implements EventStreamFilterInterface
{
    /**
     * @var string
     */
    private $correlationIdentifier;

    /**
     * @var int
     */
    private $maximumSequenceNumber;

    /**
     * CorrelationIdentifierFilter constructor.
     *
     * @param string $correlationIdentifier
     * @param int $maximumSequenceNumber
     * @throws Exception
     */
    public function __construct(string $correlationIdentifier, int $maximumSequenceNumber)
    {
        $correlationIdentifier = trim($correlationIdentifier);
        if ($correlationIdentifier === '') {
            throw new Exception('Empty stream filter provided', 1523361404);
        }
        $this->correlationIdentifier = $correlationIdentifier;
        $this->maximumSequenceNumber = $maximumSequenceNumber;
    }

    /**
     * @return array
     */
    public function getFilterValues(): array
    {
        return [
            self::FILTER_CORRELATION_IDENTIFIER => $this->correlationIdentifier,
            self::FILTER_MAXIMUM_SEQUENCE_NUMBER => $this->maximumSequenceNumber,
        ];
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function getFilterValue(string $name)
    {
        switch ($name) {
            case self::FILTER_CORRELATION_IDENTIFIER:
                return $this->correlationIdentifier;
            case self::FILTER_MAXIMUM_SEQUENCE_NUMBER:
                return $this->maximumSequenceNumber;
            break;
        }
    }
}
