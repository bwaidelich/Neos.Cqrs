<?php
namespace Neos\EventSourcing\EventStore;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Error\Messages\Result;
use Neos\EventSourcing\EventStore\Exception\EventStreamNotFoundException;
use Neos\EventSourcing\EventStore\Storage\EventStorageInterface;
use Neos\EventSourcing\EventStore\Stream\EventStream;
use Neos\EventSourcing\EventStore\Stream\Filter\EventStreamFilterInterface;

/**
 * Main API to store and fetch events.
 *
 * NOTE: Do not instantiate this class directly but use the EventStoreManager
 */
final class EventStore
{
    /**
     * @var EventStorageInterface
     */
    private $storage;

    /**
     * @internal Do not instantiate this class directly but use the EventStoreManager
     * @param EventStorageInterface $storage
     */
    public function __construct(EventStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * @param EventStreamFilterInterface $filter
     * @return EventStream Can be empty stream
     * @throws EventStreamNotFoundException
     * @todo improve exception message, log the current filter type and configuration
     */
    public function get(EventStreamFilterInterface $filter): EventStream
    {
        $eventStream = $this->storage->load($filter);
        if (!$eventStream->valid()) {
            $streamName = $filter->getFilterValue(EventStreamFilterInterface::FILTER_STREAM_NAME) ?? 'unknown stream';
            throw new EventStreamNotFoundException(sprintf('The event stream "%s" does not appear to be valid.', $streamName), 1477497156);
        }
        return $eventStream;
    }

    /**
     * @param EventStreamFilterInterface $filter
     * @return int
     */
    public function getStreamVersion(EventStreamFilterInterface $filter): int
    {
        return $this->storage->getStreamVersion($filter);
    }


    /**
     * @param string $streamName
     * @param WritableEvents $events
     * @param int $expectedVersion
     * @return RawEvent[]
     */
    public function commit(string $streamName, WritableEvents $events, int $expectedVersion = ExpectedVersion::ANY): array
    {
        return $this->storage->commit($streamName, $events, $expectedVersion);
    }

    /**
     * Returns the (connection) status of this Event Store, @see EventStorageInterface::getStatus()
     *
     * @return Result
     */
    public function getStatus(): Result
    {
        return $this->storage->getStatus();
    }

    /**
     * Sets up this Event Store and returns a status, @see EventStorageInterface::setup()
     *
     * @return Result
     */
    public function setup(): Result
    {
        return $this->storage->setup();
    }
}
