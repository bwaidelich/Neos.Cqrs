# Glossary

A list of Event Sourcing specific terms with a definition on how we currently understand it.

## Causation Identifier

An optional identifier that can be assigned to events to communicate what _caused_ the event. This could be the identifier of a Command or a preceding event.
A Causation Identifier can be useful for debugging for example in order to trace back the origin of a given event.
See [Event Correlation](#event-correlation)

<details><summary><b>Example</b></summary>

```php
<?php
public function whenOrderWasFinalized(OrderWasFinalized $event, RawEvent $rawEvent): void
{
    // send order confirmation ...

    // set the id of the handled event as causation identifier of the new event
    $newEvent = DecoratedEvent::addCausationIdentifier(
        new OrderConfirmationWasSent($payload),
        $rawEvent->getIdentifier()
    ];
    $this->eventStore->commit($streamName, DomainEvents::withSingleEvent($newEvent));
}
```
</details>

## Correlation Identifier

An optional identifier that can be assigned to events to _correlate_ them with 
See [Event Correlation](#event-correlation)

<details><summary><b>Example</b></summary>

```php
<?php
public function whenOrderWasFinalized(OrderWasFinalized $event): void
{
    // send order confirmation ...

    // correlate the new event with the order identifier of the handled event
    $newEvent = DecoratedEvent::addCorrelationIdentifier(
        new OrderConfirmationWasSent($payload),
        $event->getOrderId()
    ];
    $this->eventStore->commit($streamName, DomainEvents::withSingleEvent($newEvent));
}
```
</details>

## Domain Event

According to Martin Fowler a _Domain Event_ "captures the memory of something interesting which affects the domain"<sup id="a1">[1](#f1)</sup>.
Whether something is "interesting" might be a matter of discussions of course. But it should be clear that this is about incidents that happened in the domain logic rather than technical things (like mouse events in Javascript) – unless they are part
of the domain of course.

Because an event is something that happened in the past, they should be formulated as verbs in the _Past Tense_ form.<sup id="a2">[2](#f2)</sup>.

In this package a Domain Event is represented as a PHP class that...

* ...**MUST** implement the marker interface `Neos\EventSourcing\Event\DomainEventInterface`
* ...**SHOULD** be immutable and `final`
* ...**SHOULD** be formulated in _Present Perfect Tense_ (for example `MessageWasSent`)
* ...**MUST** have a getter for every field that is specified as constructor argument (or use public fields) so that it can be (de)serialized automatically<sup id="a3">[3](#f3)</sup>

<details><summary><b>Example</b></summary>

```php
<?php
declare(strict_types=1);
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

## Event Correlation

Especially in larger applications it can get very difficult to keep track of the event flow. Commands can lead to multiple events that in turn can lead to other follow-up events, potentially all in different Event Store Streams.
It is a good practice to correlate those events so that the flow can be traced back to the originating trigger.
With this practice every Event is enriched with _three_ identifiers before it is dispatched:

* The [Event Identifier](#event-identifier) is the globally unique id of the event (assigned automatically if not specified explicitly)
* The [Causation Identifier](#causation-identifier) is the id of the preceding message that let to the new event
* The [Correlation Identifier](#correlation-identifier) an id all messages of one process share. It is usually generated once and then copied for all succeeding events that were triggered by the original message

<details><summary><b>Example</b></summary>

```php
<?php
class SomeCommandHandler
{
    public function handleFinalizeOrder(FinalizeOrder $command): void
    {
        // validate command ...

        // create a new DomainEventInterface instance
        $event = new OrderWasFinalized($command->getOrderId());
        // ...with the command id as the *causation identifier*
        $event = DecoratedEvent::addCausationIdentifier($event, $command->getId());
        // ...and a new *correlation identifier* (alternatively the correlation id could be generated at command time)
        $correlationId = Algorithms::generateUUID();
        $event = DecoratedEvent::addCorrelationIdentifier($event, $correlationId);

        // ...

        // publish event
        $this->eventStore->commit($streamName, DomainEvents::withSingleEvent($event));
    }
}
```

```php
<?php
class SomeProcessManager implements EventListenerInterface
{
    public function whenOrderWasFinalized(OrderWasFinalized $event, RawEvent $rawEvent): void
    {
        // send order confirmation ...
    
        // create a new DomainEventInterface instance
        $newEvent = new OrderConfirmationWasSent($event->getOrderId(), $recipientId);
        // ...with the original event's identifier as *causation identifier*
        $newEvent = DecoratedEvent::addCausationIdentifier($newEvent, $rawEvent->getIdentifier());
        // ...and the same *correlation identifier*
        $newEvent = DecoratedEvent::addCorrelationIdentifier($newEvent, $rawEvent->getMetadata()['correlationIdentifier']);

        // ...

        // publish event
        $this->eventStore->commit($streamName, DomainEvents::withSingleEvent($newEvent));
    }
}
```
</details>

## Event Identifier

A globally unique identifier (usually a UUID) that is assigned to an event when it is committed to the Event Store unless it has been assigned manually before.

<details><summary><b>Example</b></summary>

```php
<?php
// assign event id manually
$eventWithId = DecoratedEvent::addIdentifier($domainEvent, 'some-id');
```
</details>

See [Event Correlation](#event-correlation)

## Event Store

The Event Store provides an API to load and persist Domain Events.
Unlike other databases it only ever *appends* events to streams, they are never *updated* or *deleted*.

## Event Store Streams

An Event Store can contain multiple streams.

When _writing_ (i.e. committing) events to a the Event Store, the target stream has to be specified. Events are always appended to that stream and are assigned a `version` that behaves like an autogenerated sequence in a regular database _within the
given stream_.
Furthermore a `sequenceNumber` is assigned to the event, that acts like a global autogenerated index and is _unique_ throughout the whole Event Store.

Events can be _read_ from a specific stream or from – what we call it – _virtual_ streams.<sup id="a4">[4](#f4)</sup>.
The following virtual streams are currently supported:

* `StreamName::all()` will load events from _all_ streams of the Event Store
* `StreamName::forCategory('some-category')` will load events from all streams starting with the given string (e.g. "some-category-foo", "some-category-bar", ...)
* `StreamName::forCorrelationId('some-correlation-id')` will load events with the given [Correlation Identifier](#correlation-identifier)

The events are always sorted by their global `sequenceNumber` so that the ordering is deterministic.

---

<sup id="f1">1</sup>: Martin Fowler on _Domain Events_: https://martinfowler.com/eaaDev/DomainEvent.html [↩](#a1)

<sup id="f2">2</sup>: We even use _Present Perfect Tense_ for Domain Events because that reads better in conjunction with `when*()` handlers (`whenMessageSent` => `whenMessageWasSent`) [↩](#a2)

<sup id="f3">3</sup>: The [Symfony Serializer](https://symfony.com/doc/current/components/serializer.html) is used for event serialization and normalization, so instances have to be compatible with the `ObjectNormalizer` [↩](#a3)

<sup id="f4">4</sup>: The "virtual streams" are heavily inspired by [eventstore.org](https://eventstore.org/) [↩](#a4)
