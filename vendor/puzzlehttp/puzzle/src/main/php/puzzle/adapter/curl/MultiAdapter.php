<?php

/**
 * HTTP adapter that uses cURL multi as a transport layer
 *
 * When using the puzzle_adapter_curl_CurlAdapter, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of a request's configuration options.
 *
 * In addition to being able to supply configuration options via the curl
 * request config, you can also specify the select_timeout variable using the
 * `GUZZLE_CURL_SELECT_TIMEOUT` environment variable.
 */
class puzzle_adapter_curl_MultiAdapter implements puzzle_adapter_AdapterInterface, puzzle_adapter_ParallelAdapterInterface
{
    const ERROR_STR = 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html for an explanation of cURL errors';
    const ENV_SELECT_TIMEOUT = 'GUZZLE_CURL_SELECT_TIMEOUT';

    /** @var puzzle_adapter_curl_CurlFactory */
    private $curlFactory;

    /** @var puzzle_message_MessageFactoryInterface */
    private $messageFactory;

    /** @var array Array of curl multi handles */
    private $multiHandles = array();

    /** @var array Array of curl multi handles */
    private $multiOwned = array();

    /** @var double */
    private $selectTimeout;

    /**
     * Accepts an associative array of options:
     *
     * - handle_factory: Optional callable factory used to create cURL handles.
     *   The callable is invoked with the following arguments:
     *   puzzle_adapter_TransactionInterface, puzzle_message_MessageFactoryInterface, and an optional cURL
     *   handle to modify. The factory method must then return a cURL resource.
     * - select_timeout: Specify a float in seconds to use for a
     *   curl_multi_select timeout.
     *
     * @param puzzle_message_MessageFactoryInterface $messageFactory
     * @param array $options Array of options to use with the adapter:
     */
    public function __construct(
        puzzle_message_MessageFactoryInterface $messageFactory,
        array $options = array()
    ) {
        $this->messageFactory = $messageFactory;
        $this->curlFactory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new puzzle_adapter_curl_CurlFactory();

        if (isset($options['select_timeout'])) {
            $this->selectTimeout = $options['select_timeout'];
        } elseif (isset($_SERVER[self::ENV_SELECT_TIMEOUT])) {
            $this->selectTimeout = $_SERVER[self::ENV_SELECT_TIMEOUT];
        } else {
            $this->selectTimeout = 1;
        }
    }

    public function __destruct()
    {
        foreach ($this->multiHandles as $handle) {
            if (is_resource($handle)) {
                curl_multi_close($handle);
            }
        }
    }

    /**
     * Throw an exception for a cURL multi response
     *
     * @param int $code Curl response code
     * @throws puzzle_exception_AdapterException
     */
    public static function throwMultiError($code)
    {
        $buffer = function_exists('curl_multi_strerror')
            ? curl_multi_strerror($code)
            : self::ERROR_STR;

        throw new puzzle_exception_AdapterException(sprintf('cURL error %s: %s', $code, $buffer));
    }

    public function send(puzzle_adapter_TransactionInterface $transaction)
    {
        $context = new puzzle_adapter_curl_BatchContext($this->checkoutMultiHandle(), true);
        $this->addHandle($transaction, $context);
        $this->perform($context);

        return $transaction->getResponse();
    }

    public function sendAll(Iterator $transactions, $parallel)
    {
        $context = new puzzle_adapter_curl_BatchContext(
            $this->checkoutMultiHandle(),
            false,
            $transactions
        );

        foreach (new LimitIterator($transactions, 0, $parallel) as $trans) {
            $this->addHandle($trans, $context);
        }

        $this->perform($context);
    }

    private function perform(puzzle_adapter_curl_BatchContext $context)
    {
        // The first curl_multi_select often times out no matter what, but is
        // usually required for fast transfers.
        $active = false;
        $multi = $context->getMultiHandle();

        do {
            do {
                $mrc = curl_multi_exec($multi, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            if ($mrc != CURLM_OK) {
                self::throwMultiError($mrc);
            }

            $this->processMessages($context);

            if ($active &&
                curl_multi_select($multi, $this->selectTimeout) === -1
            ) {
                // Perform a usleep if a select returns -1.
                // See: https://bugs.php.net/bug.php?id=61141
                usleep(250);
            }

        } while ($context->isActive() || $active);

        $this->releaseMultiHandle($multi);
    }

    private function processMessages(puzzle_adapter_curl_BatchContext $context)
    {
        $multi = $context->getMultiHandle();

        while ($done = curl_multi_info_read($multi)) {
            $transaction = $context->findTransaction($done['handle']);
            $this->processResponse($transaction, $done, $context);
            // Add the next transaction if there are more in the queue
            if ($next = $context->nextPending()) {
                $this->addHandle($next, $context);
            }
        }
    }

    private function processResponse(
        puzzle_adapter_TransactionInterface $transaction,
        array $curl,
        puzzle_adapter_curl_BatchContext $context
    ) {
        $info = $context->removeTransaction($transaction);

        try {
            if (!$this->isCurlException($transaction, $curl, $context, $info) &&
                $this->validateResponseWasSet($transaction, $context)
            ) {
                puzzle_event_RequestEvents::emitComplete($transaction, $info);
            }
        } catch (Exception $e) {
            $this->throwException($e, $context);
        }
    }

    private function addHandle(
        puzzle_adapter_TransactionInterface $transaction,
        puzzle_adapter_curl_BatchContext $context
    ) {
        try {
            puzzle_event_RequestEvents::emitBefore($transaction);
            // Only transfer if the request was not intercepted
            if (!$transaction->getResponse()) {
                $factory = $this->curlFactory;
                $context->addTransaction(
                    $transaction,
                    call_user_func_array(array($factory, '__invoke'), array($transaction, $this->messageFactory))
                );
            }
        } catch (puzzle_exception_RequestException $e) {
            $this->throwException($e, $context);
        }
    }

    private function isCurlException(
        puzzle_adapter_TransactionInterface $transaction,
        array $curl,
        puzzle_adapter_curl_BatchContext $context,
        array $info
    ) {
        if (CURLM_OK == $curl['result'] ||
            CURLM_CALL_MULTI_PERFORM == $curl['result']
        ) {
            return false;
        }

        $request = $transaction->getRequest();
        try {
            // Send curl stats along if they are available
            $stats = array('curl_result' => $curl['result']) + $info;
            puzzle_event_RequestEvents::emitError(
                $transaction,
                new puzzle_exception_RequestException(
                    sprintf(
                        '[curl] (#%s) %s [url] %s',
                        $curl['result'],
                        function_exists('curl_strerror')
                            ? curl_strerror($curl['result'])
                            : self::ERROR_STR,
                        $request->getUrl()
                    ),
                    $request
                ),
                $stats
            );
        } catch (Exception $e) {
            $this->throwException($e, $context);
        }

        return true;
    }

    private function throwException(Exception $e, puzzle_adapter_curl_BatchContext $context)
    {
        if ($context->throwsExceptions()
            || ($e instanceof puzzle_exception_RequestException && $e->getThrowImmediately())
        ) {
            $context->removeAll();
            $this->releaseMultiHandle($context->getMultiHandle());
            throw $e;
        }
    }

    /**
     * Returns a curl_multi handle from the cache or creates a new one
     *
     * @return resource
     */
    private function checkoutMultiHandle()
    {
        // Find an unused handle in the cache
        $key = array_search(false, $this->multiOwned, true);
        if (false !== $key) {
            $this->multiOwned[$key] = true;
            return $this->multiHandles[$key];
        }

        // Add a new handle
        $handle = curl_multi_init();
        $id = (int) $handle;
        $this->multiHandles[$id] = $handle;
        $this->multiOwned[$id] = true;

        return $handle;
    }

    /**
     * Releases a curl_multi handle back into the cache and removes excess cache
     *
     * @param resource $handle Curl multi handle to remove
     */
    private function releaseMultiHandle($handle)
    {
        $id = (int) $handle;

        if (count($this->multiHandles) <= 3) {
            $this->multiOwned[$id] = false;
        } else {
            // Prune excessive handles
            curl_multi_close($this->multiHandles[$id]);
            unset($this->multiHandles[$id], $this->multiOwned[$id]);
        }
    }

    /**
     * Hack for PHP 5.2. Do not use outside of testing!
     */
    public function __isCurlException(puzzle_adapter_TransactionInterface $transaction,
                                      array $curl,
                                      puzzle_adapter_curl_BatchContext $context,
                                      array $info)
    {
        return $this->isCurlException($transaction, $curl, $context, $info);
    }

    /*
     * This function ensures that a response was set on a transaction. If one
     * was not set, then the request is retried if possible. This error
     * typically means you are sending a payload, curl encountered a
     * "Connection died, retrying a fresh connect" error, tried to rewind the
     * stream, and then encountered a "necessary data rewind wasn't possible"
     * error, causing the request to be sent through curl_multi_info_read()
     * without an error status.
     *
     * @param puzzle_adapter_TransactionInterface $transaction
     * @param puzzle_adapter_curl_BatchContext    $context
     *
     * @return bool Returns true if it's OK, and false if it failed.
     * @throws puzzle_exception_RequestException If it failed and cannot
     *                                                recover.
     */
    private function validateResponseWasSet(
        puzzle_adapter_TransactionInterface $transaction,
        puzzle_adapter_curl_BatchContext $context
    ) {
        if ($transaction->getResponse()) {
            return true;
        }

        $body = $transaction->getRequest()->getBody();

        if (!$body) {
            // This is weird and should probably never happen.
            puzzle_event_RequestEvents::emitError(
                $transaction,
                new puzzle_exception_RequestException(
                    'No response was received for a request with no body. This'
                    . ' could mean that you are saturating your network.',
                    $transaction->getRequest()
                )
            );
        } elseif (!$body->isSeekable() || !$body->seek(0)) {
            // Nothing we can do with this. Sorry!
            puzzle_event_RequestEvents::emitError(
                $transaction,
                new puzzle_exception_RequestException(
                    'The connection was unexpectedly closed. The request would'
                    . ' have been retried, but attempting to rewind the'
                    . ' request body failed. Consider wrapping your request'
                    . ' body in a CachingStream decorator to work around this'
                    . ' issue if necessary.',
                    $transaction->getRequest()
                )
            );
        } else {
            $this->retryFailedConnection($transaction, $context);
        }

        return false;
    }

    private function retryFailedConnection(
        puzzle_adapter_TransactionInterface $transaction,
        puzzle_adapter_curl_BatchContext $context
    ) {
        // Add the request back to the batch to retry automatically.
        $context->addTransaction(
            $transaction,
            $this->curlFactory->__invoke($transaction, $this->messageFactory)
        );
    }
}
