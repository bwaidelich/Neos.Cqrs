<?php
namespace Neos\EventSourcing\EventListener;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\EventSourcing\Event\Decorator\EventWithCorrelationIdentifier;
use Neos\EventSourcing\Event\EventInterface;
use Neos\EventSourcing\Event\EventPublisher;
use Neos\EventSourcing\Event\EventTypeResolver;
use Neos\EventSourcing\EventListener\Exception\EventCantBeAppliedException;
use Neos\EventSourcing\EventStore\EventStoreManager;
use Neos\EventSourcing\EventStore\Exception\EventStreamNotFoundException;
use Neos\EventSourcing\EventStore\RawEvent;
use Neos\EventSourcing\EventStore\Stream\EventStream;
use Neos\EventSourcing\EventStore\Stream\Filter\CorrelationIdentifierFilter;
use Neos\EventSourcing\EventStore\Stream\StreamNameResolver;
use Neos\EventSourcing\Exception;
use Neos\EventSourcing\ProcessManager\AbstractEventSourcedProcessManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ClassReflection;
use Neos\Flow\Reflection\ReflectionService;

/**
 * Central authority for Event Listeners
 *
 * @Flow\Scope("singleton")
 */
class EventListenerManager
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var EventTypeResolver
     */
    private $eventTypeService;

    /**
     * @Flow\Inject
     * @var EventStoreManager
     */
    protected $eventStoreManager;

    /**
     * @Flow\Inject
     * @var EventPublisher
     */
    protected $eventPublisher;

    /**
     * @Flow\Inject
     * @var StreamNameResolver
     */
    protected $streamNameResolver;

    /**
     * @var array in the format ['<eventListenerIdentifier>' => ['className' => '<listenerClassName>', 'eventClassNames' => ['<eventClassName1>', '<eventClassName2>', ...]]]
     */
    private $eventListeners = [];

    /**
     * @param ObjectManagerInterface $objectManager
     * @param EventTypeResolver $eventTypeService
     */
    public function __construct(ObjectManagerInterface $objectManager, EventTypeResolver $eventTypeService)
    {
        $this->objectManager = $objectManager;
        $this->eventTypeService = $eventTypeService;
    }

    /**
     * Register event listeners based on annotations
     */
    public function initializeObject()
    {
        $this->eventListeners = self::detectListeners($this->objectManager);
    }

    public function getEventClassNamesAndListeners(): array
    {
        return $this->eventListeners;
    }

    /**
     * Invokes the "when*()" method of the given $eventListener for the specified $event
     * Additionally this invokes beforeInvokingEventListenerMethod(), saveHighestAppliedSequenceNumber() and afterInvokingEventListenerMethod()
     * if the EventListener implements the corresponding interfaces
     *
     * @param string $eventListenerIdentifier
     * @param EventInterface $event
     * @param RawEvent $rawEvent
     * @throws EventCantBeAppliedException
     */
    public function invokeListener(string $eventListenerIdentifier, EventInterface $event, RawEvent $rawEvent)
    {
        $this->verifyEventListenerIdentifier($eventListenerIdentifier);
        $eventListenerClassName = $this->getEventListenerClassName($eventListenerIdentifier);
        if (is_subclass_of($eventListenerClassName, AbstractEventSourcedProcessManager::class)) {
            $eventStore = $this->eventStoreManager->getEventStoreForEventListener($eventListenerClassName);
            #$eventStreamFilter = call_user_func($eventListenerClassName . '::getEventStreamFilter', $event, $rawEvent);
            $correlationIdentifier = $rawEvent->getMetadata()['correlationIdentifier'];
            $eventStreamFilter = new CorrelationIdentifierFilter($correlationIdentifier, $rawEvent->getSequenceNumber() - 1);
            try {
                $eventStream = $eventStore->get($eventStreamFilter);
            } catch (EventStreamNotFoundException $exception) {
                $eventStream = new EventStream(new \ArrayIterator());
            }

            $eventListener = call_user_func($eventListenerClassName . '::reconstituteFromEventStream', $correlationIdentifier, $eventStream);
        } else {
            $eventListener = $this->objectManager->get($eventListenerClassName);
        }
        $this->invokeListenerOnObject($eventListener, $event, $rawEvent);

    }

    public function invokeListenerOnObject(EventListenerInterface $eventListener, EventInterface $event, RawEvent $rawEvent)
    {
        if ($eventListener instanceof ActsBeforeInvokingEventListenerMethodsInterface) {
            $eventListener->beforeInvokingEventListenerMethod($event, $rawEvent);
        }
        $eventClassName = $this->eventTypeService->getEventClassNameByType($rawEvent->getType());
        // TODO: no reflection at runtime!?
        $eventListenerMethodName = 'when' . (new ClassReflection($eventClassName))->getShortName();
        call_user_func([$eventListener, $eventListenerMethodName], $event, $rawEvent);
        if ($eventListener instanceof ActsAfterInvokingEventListenerMethodsInterface) {
            $eventListener->afterInvokingEventListenerMethod($event, $rawEvent);
        }
        if ($eventListener instanceof AbstractEventSourcedProcessManager) {
            $streamName = $this->streamNameResolver->getStreamNameForAggregate($eventListener);
            #$expectedVersion = $eventListener->getReconstitutionVersion();
            $correlationIdentifier = $rawEvent->getMetadata()['correlationIdentifier'];
            $eventsWithCorrelationIdentifier = array_map(function(EventInterface $event) use ($correlationIdentifier) {
                return new EventWithCorrelationIdentifier($event, $correlationIdentifier);
            }, $eventListener->pullUncommittedEvents());
            $this->eventPublisher->publishMany($streamName, $eventsWithCorrelationIdentifier);
        }
    }

    /**
     * @return string[]
     */
    public function getAsynchronousListenerClassNames(): array
    {
        $asynchronousListenerClassNames = [];
        array_walk($this->eventListeners, function ($listenerMappings) use (&$asynchronousListenerClassNames) {
            foreach (array_keys($listenerMappings) as $listenerMappingClassName) {
                if (!in_array($listenerMappingClassName, $asynchronousListenerClassNames) && is_subclass_of($listenerMappingClassName, AsynchronousEventListenerInterface::class)) {
                    $asynchronousListenerClassNames[] = $listenerMappingClassName;
                }
            }
        });
        return $asynchronousListenerClassNames;
    }

    /**
     * @param string $listenerClassName
     * @return string[]
     */
    public function getEventTypesByListenerClassName(string $listenerClassName): array
    {
        $eventTypes = [];
        array_walk($this->eventListeners, function (array $listenerInfo) use (&$eventTypes, $listenerClassName) {
            if ($listenerInfo['className'] !== $listenerClassName) {
                return;
            }
            $eventTypes = array_map(function(string $eventClassName) {
                return $this->eventTypeService->getEventTypeByClassName($eventClassName);
            }, $listenerInfo['eventClassNames']);
        });
        return $eventTypes;
    }

    public function getEventListenerIdentifiers(): array
    {
        return array_keys($this->eventListeners);
    }

    public function getEventListenerClassName(string $eventListenerIdentifier): string
    {
        $this->verifyEventListenerIdentifier($eventListenerIdentifier);
        return $this->eventListeners[$eventListenerIdentifier]['className'];
    }

    private function verifyEventListenerIdentifier(string $eventListenerIdentifier): void
    {
        if (!array_key_exists($eventListenerIdentifier, $this->eventListeners)) {
            throw new \InvalidArgumentException(sprintf('The EventListener with identifier "%s" could not be found', $eventListenerIdentifier), 1521561079);
        }
    }

    public function getEventTypesByListener(string $eventListenerIdentifier): array
    {
        $this->verifyEventListenerIdentifier($eventListenerIdentifier);
        $eventTypes = [];
        array_walk($this->eventListeners[$eventListenerIdentifier]['eventClassNames'], function ($eventClassName) use (&$eventTypes) {
            $eventTypes[] = $this->eventTypeService->getEventTypeByClassName($eventClassName);
        });
        return $eventTypes;
    }

    /**
     * Detects and collects all existing event listener classes
     *
     * @param ObjectManagerInterface $objectManager
     * @return array in the format ['<eventListenerIdentifier>' => ['className' => '<listenerClassName>', 'eventClassNames' => ['<eventClassName>', '<eventClassName>', ...]]]
     * @throws Exception
     * @Flow\CompileStatic
     */
    protected static function detectListeners(ObjectManagerInterface $objectManager): array
    {
        $listeners = [];
        /** @var ReflectionService $reflectionService */
        $reflectionService = $objectManager->get(ReflectionService::class);
        foreach ($reflectionService->getAllImplementationClassNamesForInterface(EventListenerInterface::class) as $listenerClassName) {
            $shortListenerClassName = (new ClassReflection($listenerClassName))->getShortName();
            $eventClassNames = [];
            foreach (get_class_methods($listenerClassName) as $listenerMethodName) {
                preg_match('/^when[A-Z].*$/', $listenerMethodName, $matches);
                if (!isset($matches[0])) {
                    continue;
                }
                $parameters = array_values($reflectionService->getMethodParameters($listenerClassName, $listenerMethodName));

                if (!isset($parameters[0])) {
                    throw new Exception(sprintf('Invalid listener in %s::%s the method signature is wrong, must accept an EventInterface and optionally a RawEvent', $listenerClassName, $listenerMethodName), 1472500228);
                }
                $eventClassName = $parameters[0]['class'];
                if (!$reflectionService->isClassImplementationOf($eventClassName, EventInterface::class)) {
                    throw new Exception(sprintf('Invalid listener in %s::%s the method signature is wrong, the first parameter should be an implementation of EventInterface but it expects an instance of "%s"', $listenerClassName, $listenerMethodName, $eventClassName), 1472504443);
                }

                if (isset($parameters[1])) {
                    $rawEventDataType = $parameters[1]['class'];
                    if ($rawEventDataType !== RawEvent::class) {
                        throw new Exception(sprintf('Invalid listener in %s::%s the method signature is wrong. If the second parameter is present, it has to be a RawEvent but it expects an instance of "%s"', $listenerClassName, $listenerMethodName, $rawEventDataType), 1472504303);
                    }
                }
                $expectedMethodName = 'when' . (new ClassReflection($eventClassName))->getShortName();
                if ($expectedMethodName !== $listenerMethodName) {
                    throw new Exception(sprintf('Invalid listener in %s::%s the method name is expected to be "%s"', $listenerClassName, $listenerMethodName, $expectedMethodName), 1476442394);
                }
                $eventClassNames[] = $eventClassName;
            }
            if ($eventClassNames === []) {
                throw new Exception(sprintf('No listener methods have been detected in listener class %s. A listener has the signature "public function when<EventClass>(<EventClass> $event) {}" and every EventListener class has to implement at least one listener!', $listenerClassName), 1498123537);
            }
            $packageKey = strtolower($objectManager->getPackageKeyByObjectName($listenerClassName));
            $listenerIdentifier = $packageKey . ':' . strtolower($shortListenerClassName);
            if (isset($listeners[$listenerIdentifier])) {
                throw new \RuntimeException(sprintf('The EventListener identifier "%s" is ambiguous, please rename one of the classes "%s" or "%s"', $listenerIdentifier, $listeners[$listenerIdentifier], $listenerClassName), 1521553940);
            }
            $listeners[$listenerIdentifier] = ['className' => $listenerClassName, 'eventClassNames' => $eventClassNames];
        }
        return $listeners;
    }
}
