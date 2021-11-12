<?php

/**
 * Contains methods used to manage the request event lifecycle.
 */
final class puzzle_event_RequestEvents
{
    // Generic event priorities
    const EARLY = 10000;
    const LATE = -10000;

    // "before" priorities
    const PREPARE_REQUEST = -100;
    const SIGN_REQUEST = -10000;

    // "complete" and "error" response priorities
    const VERIFY_RESPONSE = 100;
    const REDIRECT_RESPONSE = 200;

    /**
     * Emits the before send event for a request and emits an error
     * event if an error is encountered during the before send.
     *
     * @param puzzle_adapter_TransactionInterface $transaction
     *
     * @throws puzzle_exception_RequestException
     */
    public static function emitBefore(puzzle_adapter_TransactionInterface $transaction) {
        $request = $transaction->getRequest();
        try {
            $request->getEmitter()->emit(
                'before',
                new puzzle_event_BeforeEvent($transaction)
            );
        } catch (puzzle_exception_RequestException $e) {
            // When a puzzle_exception_RequestException has been emitted through emitError, the
            // exception is marked as "emitted". This means that the exception
            // had a chance to be rescued but was not. In this case, this method
            // must not emit the error again, but rather throw the exception.
            // This prevents puzzle_exception_RequestExceptions encountered during the before
            // event from being emitted to listeners twice.
            if ($e->emittedError()) {
                throw $e;
            }
            self::emitError($transaction, $e);
        } catch (Exception $e) {
            self::emitError($transaction, $e);
        }
    }

    /**
     * Emits the complete event for a request and emits an error
     * event if an error is encountered during the after send.
     *
     * @param puzzle_adapter_TransactionInterface $transaction Transaction to emit for
     * @param array                $stats       Transfer stats
     *
     * @throws puzzle_exception_RequestException
     */
    public static function emitComplete(
        puzzle_adapter_TransactionInterface $transaction,
        array $stats = array()
    ) {
        $request = $transaction->getRequest();
        $transaction->getResponse()->setEffectiveUrl($request->getUrl());
        try {
            $request->getEmitter()->emit(
                'complete',
                new puzzle_event_CompleteEvent($transaction, $stats)
            );
        } catch (puzzle_exception_RequestException $e) {
            self::emitError($transaction, $e, $stats);
        }
    }

    /**
     * Emits the headers event for a request.
     *
     * @param puzzle_adapter_TransactionInterface $transaction Transaction to emit for
     */
    public static function emitHeaders(puzzle_adapter_TransactionInterface $transaction)
    {
        $transaction->getRequest()->getEmitter()->emit(
            'headers',
            new puzzle_event_HeadersEvent($transaction)
        );
    }

    /**
     * Emits an error event for a request and accounts for the propagation
     * of an error event being stopped to prevent the exception from being
     * thrown.
     *
     * @param puzzle_adapter_TransactionInterface $transaction
     * @param Exception           $e
     * @param array                $stats
     *
     * @throws puzzle_exception_RequestException
     */
    public static function emitError(
        puzzle_adapter_TransactionInterface $transaction,
        Exception $e,
        array $stats = array()
    ) {
        $request = $transaction->getRequest();

        // Convert non-request exception to a wrapped exception
        if (!($e instanceof puzzle_exception_RequestException)) {
            $e = new puzzle_exception_RequestException($e->getMessage(), $request, null, $e);
        }

        // Mark the exception as having been emitted for an error event. This
        // works in tandem with the emitBefore method to prevent the error
        // event from being triggered twice for the same exception.
        $e->emittedError(true);

        // Dispatch an event and allow interception
        if (!$request->getEmitter()->emit(
            'error',
            new puzzle_event_ErrorEvent($transaction, $e, $stats)
        )->isPropagationStopped()) {
            throw $e;
        }
    }

    /**
     * Converts an array of event options into a formatted array of valid event
     * configuration.
     *
     * @param array $options Event array to convert
     * @param array $events  Event names to convert in the options array.
     * @param mixed $handler Event handler to utilize
     *
     * @return array
     * @throws InvalidArgumentException if the event config is invalid
     * @internal
     */
    public static function convertEventArray(
        array $options,
        array $events,
        $handler
    ) {
        foreach ($events as $name) {
            if (!isset($options[$name])) {
                $options[$name] = array($handler);
            } elseif (is_callable($options[$name])) {
                $options[$name] = array($options[$name], $handler);
            } elseif (is_array($options[$name])) {
                if (isset($options[$name]['fn'])) {
                    $options[$name] = array($options[$name], $handler);
                } else {
                    $options[$name][] = $handler;
                }
            } else {
                throw new InvalidArgumentException('Invalid event format');
            }
        }

        return $options;
    }
}
