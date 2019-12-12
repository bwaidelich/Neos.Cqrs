# Event Sourcing and CQRS for Flow Framework

Library providing interfaces and implementations for event-sourced applications. 

## Getting started

Install this package via composer:

```shell script
composer require neos/event-sourcing
```

Set up the `default` Event Store:

```shell script
./flow eventstore:setup default
```

<details>
<summary>:information_source: Note:</summary>
By default the Event Store persists events in the same database that is used for Flow persistence.
But because that can be configured otherwise, this table is not generated via Doctrine migrations.
If your application relies on the events table to exist, you can of course still add a Doctrine migration for it.
</details>

### Writing events

<details>
<summary>Example event: <i>SomethingHasHappened.php</i></summary>

```php
<?php
namespace Some\Package;

use Neos\EventSourcing\Event\DomainEventInterface;

final class SomethingHasHappened implements DomainEventInterface
{
    /**
     * @var string
     */
    private $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

}
```
</details>

```php
<?php
namespace Some\Package;

use Neos\EventSourcing\Event\DomainEvents;
use Neos\EventSourcing\EventStore\EventStore;
use Neos\EventSourcing\EventStore\StreamName;
use Neos\Flow\Annotations as Flow;
use Some\Package\SomethingHasHappened;

class SomeClass
{

    /**
     * @Flow\Inject
     * @var EventStore
     */
    protected $eventStore;

    public function someMethod(): void
    {
        $domainEvent = new SomethingHasHappened('some message');
        $streamName = StreamName::fromString('some-stream');
        $this->eventStore->commit($streamName, DomainEvents::withSingleEvent($domainEvent));
    }

}
```

### Reading events

```php
<?php
namespace Some\Package;

use Neos\EventSourcing\EventStore\EventStore;
use Neos\EventSourcing\EventStore\StreamName;
use Neos\Flow\Annotations as Flow;

class SomeClass
{

    /**
     * @Flow\Inject
     * @var EventStore
     */
    protected $eventStore;

    public function someMethod(): void
    {
        $streamName = StreamName::fromString('some-stream');
        $eventStream = $this->eventStore->load($streamName);
        foreach ($eventStream as $eventEnvelope) {
            // the event as it's stored in the Event Store, including its global sequence number and the serialized payload
            $rawEvent = $eventEnvelope->getRawEvent();

            // the deserialized DomainEventInterface instance 
            $domainEvent = $eventEnvelope->getDomainEvent();
        }
    }

}
```

### Reacting to events

```php
<?php
namespace Some\Package;

use Neos\EventSourcing\EventListener\EventListenerInterface;
use Some\Package\SomethingHasHappened;

class SomeEventListener implements EventListenerInterface
{

    public function whenSomethingHasHappened(SomethingHasHappened $event): void
    {
        // do something with the $event
    }

}
```

The `when*()` methods of classes implementing the `EventListenerInterface` will be invoked whenever a corresponding event is committed to the Event Store.

## Tutorial

...to be written.

See [Glossary](Glossary.md#event-correlation)

### Basic Event Flow

* `Commands` are plain PHP objects, being the external Write API.
    * Commands SHOULD be immutable and final.
    * Commands SHOULD be written in *present tense* (Example: `CreateWorkspace`)
* For each command, a method on the corresponding `CommandHandler`
  is called. That's how you "dispatch" a command.
    * A Command Handler is a standard Flow singleton, without any required base class.
    * The handler methods SHOULD be called `handle[CommandName]([CommandName] $command)`
* Inside the `handle*` method of the command handler, one or multiple `Event`s are created from the command,
  possibly after checking soft constraints; or forwarding to an aggregate (not described here):
    * The event SHOULD be immutable and final.
    * The event MUST have the **annotation `@Flow\Proxy(false)`**
    * The event MUST implement the marker interface `Neos\EventSourcing\Event\DomainEventInterface`.
    * The event SHOULD be written in past tense. Example: `WorkspaceWasCreated`
* To actually store the event, the following must be done:
  * retrieve an instance of `Neos\EventSourcing\EventStore` (inject the default instance or use the `Neos\EventSourcing\EventStore\EventStoreFactory`)
  * specify the stream to which you want to publish events to: `$streamName = StreamName::fromString('some-stream');`
  * Commit the events by executing `$eventStore->commit($streamName, DomainEvents::withSingleEvent($event));`
* The EventStore stores the event into the *Storage* and publishes them in the *Event Bus*.
  * The Event Bus remembers that certain Event Listeners (== Projections) need to be updated.
* At the end of the request (on `shutdownObject()` of the EventBus), the job queue `neos-eventsourcing`
  receives an `CatchUpEventListenerJob` with the event listener which should be ran.
* Then, the event listeners are invoked asynchronously inside the queue.

### Aggregates

To Be Written

#### Aggregate Repository

This Framework does not provide an abstract Repository class for Aggregates, because an implementation is just a couple of lines of code and there is no useful abstraction that can be extracted. The Repository is just a slim wrapper around the EventStore and the Aggregate class. If you want to create a Repository for an Aggregate `Product` then the code would look like this:

```php
final class ProductRepository
{
    // inject an instance of EventStore somehow ...

    public function load(ProductIdentifier $id): Product
    {
        $streamName = ...; // Build the stream name from the identifier
        return Product::reconstituteFromEventStream($this->eventStore->load($streamName));
    }

    public function save(Product $product): void
    {
        $streamName = ...; // Build the stream name from $product->getIdentifier()
        $this->eventStore->commit($streamName, $product->pullUncommittedEvents(), $product->getReconstitutionVersion());
    }
}
```
