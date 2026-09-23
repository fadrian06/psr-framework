<?php

declare(strict_types=1);

use Faslatam\PsrFramework\QueueRequestHandler;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class QueueRequestHandlerTest extends TestCase
{
  private static ServerRequestFactoryInterface $serverRequestFactory;
  private static ResponseFactoryInterface $responseFactory;

  #[Override]
  public static function setUpBeforeClass(): void
  {
    parent::setUpBeforeClass();

    self::$serverRequestFactory = new HttpFactory;
    self::$responseFactory = new HttpFactory;
  }

  #[Test]
  public function handles_fallback_when_no_middlewares_provided(): void
  {
    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
      public bool $called = false;

      public function __construct(
        private ResponseFactoryInterface $responseFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->called = true;

        return $this->responseFactory->createResponse(200);
      }
    };

    $queueHandler = new QueueRequestHandler($fallbackHandler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $queueHandler->handle($request);

    self::assertTrue($fallbackHandler->called);
    self::assertSame(200, $response->getStatusCode());
  }

  #[Test]
  public function executes_single_middleware_and_fallback_handler(): void
  {
    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        return $this->responseFactory->createResponse(200);
      }
    };

    $middleware = new class implements MiddlewareInterface {
      public bool $called = false;

      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $this->called = true;
        $response = $handler->handle($request);

        return $response->withHeader('X-Middleware', 'executed');
      }
    };

    $queueHandler = new QueueRequestHandler($fallbackHandler, $middleware);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $queueHandler->handle($request);

    self::assertTrue($middleware->called);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame('executed', $response->getHeaderLine('X-Middleware'));
  }

  #[Test]
  public function executes_middlewares_in_fifo_order(): void
  {
    $executionLog = [];

    $fallbackHandler = new class(self::$responseFactory, $executionLog) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array &$executionLog,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->executionLog[] = 'fallback';

        return $this->responseFactory->createResponse(200);
      }
    };

    $createMiddleware = static function (string $id, array &$log): MiddlewareInterface {
      return new class($id, $log) implements MiddlewareInterface {
        public function __construct(
          private string $id,
          private array &$log,
        ) {}

        #[Override]
        #[NoDiscard]
        public function process(
          ServerRequestInterface $request,
          RequestHandlerInterface $handler,
        ): ResponseInterface {
          $this->log[] = "{$this->id}_start";
          $response = $handler->handle($request);
          $this->log[] = "{$this->id}_end";

          return $response;
        }
      };
    };

    $m1 = $createMiddleware('m1', $executionLog);
    $m2 = $createMiddleware('m2', $executionLog);
    $m3 = $createMiddleware('m3', $executionLog);

    $queueHandler = new QueueRequestHandler($fallbackHandler, $m1, $m2, $m3);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $queueHandler->handle($request);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame(
      ['m1_start', 'm2_start', 'm3_start', 'fallback', 'm3_end', 'm2_end', 'm1_end'],
      $executionLog,
    );
  }

  #[Test]
  public function short_circuits_execution_when_middleware_does_not_call_next(): void
  {
    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
      public bool $called = false;

      public function __construct(
        private ResponseFactoryInterface $responseFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->called = true;

        return $this->responseFactory->createResponse(200);
      }
    };

    $m1Called = false;
    $m1 = new class(self::$responseFactory, $m1Called) implements MiddlewareInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        public bool &$called,
      ) {}

      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $this->called = true;

        return $this->responseFactory->createResponse(403);
      }
    };

    $m2Called = false;
    $m2 = new class($m2Called) implements MiddlewareInterface {
      public function __construct(
        public bool &$called,
      ) {}

      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $this->called = true;

        return $handler->handle($request);
      }
    };

    $queueHandler = new QueueRequestHandler($fallbackHandler, $m1, $m2);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $queueHandler->handle($request);

    self::assertTrue($m1Called);
    self::assertFalse($m2Called);
    self::assertFalse($fallbackHandler->called);
    self::assertSame(403, $response->getStatusCode());
  }

  #[Test]
  public function passes_modified_request_down_the_queue(): void
  {
    $capturedAttribute = null;

    $fallbackHandler = new class(self::$responseFactory, $capturedAttribute) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private mixed &$capturedAttribute,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->capturedAttribute = $request->getAttribute('queue_order');

        return $this->responseFactory->createResponse(200);
      }
    };

    $m1 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $order = $request->getAttribute('queue_order', []);
        $order[] = 'm1';

        return $handler->handle($request->withAttribute('queue_order', $order));
      }
    };

    $m2 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $order = $request->getAttribute('queue_order', []);
        $order[] = 'm2';

        return $handler->handle($request->withAttribute('queue_order', $order));
      }
    };

    $queueHandler = new QueueRequestHandler($fallbackHandler, $m1, $m2);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $queueHandler->handle($request);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame(['m1', 'm2'], $capturedAttribute);
  }

  #[Test]
  public function allows_middlewares_to_modify_response(): void
  {
    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        return $this->responseFactory->createResponse(200)->withHeader('X-Handler', 'fallback');
      }
    };

    $m1 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $response = $handler->handle($request);

        return $response->withHeader('X-Middleware-1', 'first');
      }
    };

    $m2 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $response = $handler->handle($request);

        return $response->withHeader('X-Middleware-2', 'second');
      }
    };

    $queueHandler = new QueueRequestHandler($fallbackHandler, $m1, $m2);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $queueHandler->handle($request);

    self::assertSame('fallback', $response->getHeaderLine('X-Handler'));
    self::assertSame('second', $response->getHeaderLine('X-Middleware-2'));
    self::assertSame('first', $response->getHeaderLine('X-Middleware-1'));
  }
}
