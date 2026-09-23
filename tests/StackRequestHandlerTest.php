<?php

declare(strict_types=1);

use Faslatam\PsrFramework\StackRequestHandler;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class StackRequestHandlerTest extends TestCase
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

    $stackHandler = new StackRequestHandler($fallbackHandler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $stackHandler->handle($request);

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

    $stackHandler = new StackRequestHandler($fallbackHandler, $middleware);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $stackHandler->handle($request);

    self::assertTrue($middleware->called);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame('executed', $response->getHeaderLine('X-Middleware'));
  }

  #[Test]
  public function executes_middlewares_in_lifo_order(): void
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

    $stackHandler = new StackRequestHandler($fallbackHandler, $m1, $m2, $m3);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $stackHandler->handle($request);

    self::assertSame(200, $response->getStatusCode());
    // Stack is LIFO: m3 was pushed last, so it pops first
    self::assertSame(
      ['m3_start', 'm2_start', 'm1_start', 'fallback', 'm1_end', 'm2_end', 'm3_end'],
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
    $m1 = new class($m1Called) implements MiddlewareInterface {
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

    $m2Called = false;
    $m2 = new class(self::$responseFactory, $m2Called) implements MiddlewareInterface {
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

    $m3Called = false;
    $m3 = new class($m3Called) implements MiddlewareInterface {
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

    // Middleware execution order will be: m3 (pops first) -> m2 (short-circuits) -> m1 (never called) -> fallback (never called)
    $stackHandler = new StackRequestHandler($fallbackHandler, $m1, $m2, $m3);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $stackHandler->handle($request);

    self::assertTrue($m3Called);
    self::assertTrue($m2Called);
    self::assertFalse($m1Called);
    self::assertFalse($fallbackHandler->called);
    self::assertSame(403, $response->getStatusCode());
  }

  #[Test]
  public function passes_modified_request_down_the_stack(): void
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
        $this->capturedAttribute = $request->getAttribute('stack_order');

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
        $order = $request->getAttribute('stack_order', []);
        $order[] = 'm1';

        return $handler->handle($request->withAttribute('stack_order', $order));
      }
    };

    $m2 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $order = $request->getAttribute('stack_order', []);
        $order[] = 'm2';

        return $handler->handle($request->withAttribute('stack_order', $order));
      }
    };

    $m3 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $order = $request->getAttribute('stack_order', []);
        $order[] = 'm3';

        return $handler->handle($request->withAttribute('stack_order', $order));
      }
    };

    $stackHandler = new StackRequestHandler($fallbackHandler, $m1, $m2, $m3);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $stackHandler->handle($request);

    self::assertSame(200, $response->getStatusCode());
    // Executed m3 first, then m2, then m1, then fallback
    self::assertSame(['m3', 'm2', 'm1'], $capturedAttribute);
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

        return $response->withHeader('X-Middleware-1', 'm1');
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

        return $response->withHeader('X-Middleware-2', 'm2');
      }
    };

    $m3 = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $response = $handler->handle($request);

        return $response->withHeader('X-Middleware-3', 'm3');
      }
    };

    $stackHandler = new StackRequestHandler($fallbackHandler, $m1, $m2, $m3);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $stackHandler->handle($request);

    self::assertSame('fallback', $response->getHeaderLine('X-Handler'));
    self::assertSame('m1', $response->getHeaderLine('X-Middleware-1'));
    self::assertSame('m2', $response->getHeaderLine('X-Middleware-2'));
    self::assertSame('m3', $response->getHeaderLine('X-Middleware-3'));
  }
}
