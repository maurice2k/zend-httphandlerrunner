<?php
/**
 * @see       https://github.com/zendframework/zend-httphandlerrunner for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-httphandlerrunner/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\HttpHandlerRunner;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * "Run" a request handler.
 *
 * The RequestHandlerRunner will marshal a request using the composed factory, and
 * then pass the request to the composed handler. Finally, it emits the response
 * returned by the handler using the composed emitter.
 *
 * If the factory for generating the request raises an exception or throwable,
 * then the runner will use the composed error response generator to generate a
 * response, based on the exception or throwable raised.
 */
class RequestHandlerRunner
{
    /**
     * @var Emitter\EmitterInterface
     */
    private $emitter;

    /**
     * A request handler to run as the application.
     *
     * @var RequestHandlerInterface
     */
    private $handler;

    /**
     * A factory capable of generating an error response in the scenario that
     * the $serverRequestFactory raises an exception during generation of the
     * request instance.
     *
     * The factory will receive the Throwable or Exception that caused the error,
     * and must return a Psr\Http\Message\ResponseInterface instance.
     *
     * @var callable
     */
    private $serverRequestErrorResponseGenerator;

    /**
     * A factory capable of generating a Psr\Http\Message\ServerRequestInterface instance.
     * The factory will not receive any arguments.
     *
     * @var callable
     */
    private $serverRequestFactory;

    /**
     * @var callable[]
     */
    protected $listeners = [];

    public function __construct(
        RequestHandlerInterface $handler,
        Emitter\EmitterInterface $emitter,
        callable $serverRequestFactory,
        callable $serverRequestErrorResponseGenerator
    ) {
        $this->handler = $handler;
        $this->emitter = $emitter;

        // Factories are cast as Closures to ensure return type safety.
        $this->serverRequestFactory = function () use ($serverRequestFactory) : ServerRequestInterface {
            return $serverRequestFactory();
        };

        $this->serverRequestErrorResponseGenerator =
            function (Throwable $exception) use ($serverRequestErrorResponseGenerator) : ResponseInterface {
                return $serverRequestErrorResponseGenerator($exception);
            };
    }

    /**
     * Run the application
     */
    public function run() : void
    {
        try {
            $request = ($this->serverRequestFactory)();
        } catch (Throwable $e) {
            // Error in generating the request
            $this->emitMarshalServerRequestException($e);
            return;
        }

        $response = $this->handler->handle($request);

        $this->emitter->emit($response);
    }

    /**
     * Attach an error listener.
     *
     * Each listener receives the following two arguments:
     *
     * - Throwable $error
     * - ResponseInterface $response
     *
     * These instances are all immutable, and the return values of
     * listeners are ignored; use listeners for reporting purposes
     * only.
     */
    public function attachListener(callable $listener) : void
    {
        if (in_array($listener, $this->listeners, true)) {
            return;
        }

        $this->listeners[] = $listener;
    }

    /**
     * Trigger all error listeners.
     */
    private function triggerListeners(
        \Throwable $error,
        ResponseInterface $response
    ) : void {
        foreach ($this->listeners as $listener) {
            $listener($error, $response);
        }
    }

    public function emitMarshalServerRequestException(Throwable $exception) : void
    {
        $response = ($this->serverRequestErrorResponseGenerator)($exception);
        $this->triggerListeners($exception, $response);
        $this->emitter->emit($response);
    }
}
