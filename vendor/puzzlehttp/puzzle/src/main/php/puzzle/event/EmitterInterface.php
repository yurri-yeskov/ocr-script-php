<?php

/**
 * Guzzle event emitter.
 */
interface puzzle_event_EmitterInterface
{
    /**
     * Binds a listener to a specific event.
     *
     * @param string     $eventName Name of the event to bind to.
     * @param callable   $listener  Listener to invoke when triggered.
     * @param int|string $priority  The higher this value, the earlier an event
     *     listener will be triggered in the chain (defaults to 0). You can
     *     pass "first" or "last" to dynamically specify the event priority
     *     based on the current event priorities associated with the given
     *     event name in the emitter. Use "first" to set the priority to the
     *     current highest priority plus one. Use "last" to set the priority to
     *     the current lowest event priority minus one.
     */
    function on($eventName, $listener, $priority = 0);

    /**
     * Binds a listener to a specific event. After the listener is triggered
     * once, it is removed as a listener.
     *
     * @param string   $eventName Name of the event to bind to.
     * @param callable $listener  Listener to invoke when triggered.
     * @param int      $priority  The higher this value, the earlier an event
     *     listener will be triggered in the chain (defaults to 0)
     */
    function once($eventName, $listener, $priority = 0);

    /**
     * Removes an event listener from the specified event.
     *
     * @param string   $eventName The event to remove a listener from
     * @param callable $listener  The listener to remove
     */
    function removeListener($eventName, $listener);

    /**
     * Gets the listeners of a specific event or all listeners if no event is
     * specified.
     *
     * @param string $eventName The name of the event. Pass null (the default)
     *     to retrieve all listeners.
     *
     * @return array The event listeners for the specified event, or all event
     *   listeners by event name. The format of the array when retrieving a
     *   specific event list is an array of callables. The format of the array
     *   when retrieving all listeners is an associative array of arrays of
     *   callables.
     */
    function listeners($eventName = null);

    /**
     * Emits an event to all registered listeners.
     *
     * Each event that is bound to the emitted eventName receives a
     * puzzle_event_EventInterface, the name of the event, and the event emitter.
     *
     * @param string                             $eventName The name of the event to dispatch.
     * @param puzzle_event_EventInterface $event     The event to pass to the event handlers/listeners.
     *
     * @return puzzle_event_EventInterface Returns the provided event object
     */
    function emit($eventName, puzzle_event_EventInterface $event);

    /**
     * Attaches an event subscriber.
     *
     * The subscriber is asked for all the events it is interested in and added
     * as an event listener for each event.
     *
     * @param puzzle_event_SubscriberInterface $subscriber Subscriber to attach.
     */
    function attach(puzzle_event_SubscriberInterface $subscriber);

    /**
     * Detaches an event subscriber.
     *
     * @param puzzle_event_SubscriberInterface $subscriber Subscriber to detach.
     */
    function detach(puzzle_event_SubscriberInterface $subscriber);
}
