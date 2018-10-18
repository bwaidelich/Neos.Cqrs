<?php
namespace Neos\EventSourcing\ProcessManager;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\EventSourcing\Domain\EventSourcedAggregateRootInterface;
use Neos\EventSourcing\Event\EventInterface;
use Neos\EventSourcing\EventListener\EventListenerInterface;
use Neos\EventSourcing\EventStore\Stream\EventStream;

abstract class AbstractEventSourcedProcessManager implements EventSourcedAggregateRootInterface, EventListenerInterface
{

    /**
     * @var EventInterface[]
     */
    private $events = [];

    /**
     * @var int
     */
    private $reconstitutionVersion = -1;

    final public function getReconstitutionVersion(): int
    {
        return $this->reconstitutionVersion;
    }

    public function getIdentifier(): string
    {
        // TODO..
        return 'test';
    }


    /**
     * @param EventInterface $event
     * @api
     */
    final public function recordThat(EventInterface $event)
    {
        $this->apply($event);
        $this->events[] = $event;
    }

    /**
     * @return EventInterface[]
     */
    final public function pullUncommittedEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /**
     * @param  EventInterface $event
     * @return void
     */
    final protected function apply(EventInterface $event)
    {
        $methodName = sprintf('when%s', (new \ReflectionClass($event))->getShortName());
        if (method_exists($this, $methodName)) {
            $this->$methodName($event);
        }
    }

    /**
     * @param EventStream $stream
     * @return self
     */
    public static function reconstituteFromEventStream(string $identifier, EventStream $stream)
    {
        $instance = new static($identifier);
        $lastAppliedEventVersion = -1;
        foreach ($stream as $eventAndRawEvent) {
            $instance->apply($eventAndRawEvent->getEvent());
            $lastAppliedEventVersion = $eventAndRawEvent->getRawEvent()->getVersion();
        }
        $instance->events = [];
        $instance->reconstitutionVersion = $lastAppliedEventVersion;
        return $instance;
    }

    #abstract static public function getEventStreamFilter(EventInterface $event, RawEvent $rawEvent): EventStreamFilterInterface;
}
