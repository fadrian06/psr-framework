<?php

declare(strict_types=1);

use Faslatam\PsrFramework\DecoratingRequestHandler;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DecoratingRequestHandlerTest extends TestCase
{
  private static ServerRequestFactoryInterface $serverRequestFactory;
  private static ResponseFactoryInterface $responseFactory;
  private static StreamFactoryInterface $streamFactory;

  #[Override]
  public static function setUpBeforeClass(): void
  {
    parent::setUpBeforeClass();

    self::$serverRequestFactory = new HttpFactory;
    self::$responseFactory = new HttpFactory;
    self::$streamFactory = new HttpFactory;
  }

  #[Test]
  public function runs_middleware_before_handler(): void
  {
    $responseFactory = self::$responseFactory;

    $handler = new class($responseFactory) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        return $this->responseFactory->createResponse(404);
      }
    };

    $middleware = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $response = $handler->handle($request);

        return $response->withStatus(500);
      }
    };

    $decoratingHandler = new DecoratingRequestHandler($middleware, $handler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $decoratingHandler->handle($request);

    self::assertSame(500, $response->getStatusCode());
  }

  #[Test]
  public function middleware_does_not_execute_handler(): void
  {
    $handler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

        return $this->responseFactory->createResponse(404);
      }
    };

    $middleware = new class(self::$responseFactory) implements MiddlewareInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        return $this->responseFactory->createResponse(403);
      }
    };

    $decoratingHandler = new DecoratingRequestHandler($middleware, $handler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $decoratingHandler->handle($request);

    self::assertFalse($handler->called);
    self::assertSame(403, $response->getStatusCode());
  }

  #[Test]
  public function passes_exact_next_handler_to_middleware(): void
  {
    $handler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

    $capturedHandler = null;
    $middleware = new class($capturedHandler) implements MiddlewareInterface {
      public function __construct(
        private ?RequestHandlerInterface &$capturedHandler,
      ) {}

      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $this->capturedHandler = $handler;

        return $handler->handle($request);
      }
    };

    $decoratingHandler = new DecoratingRequestHandler($middleware, $handler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $decoratingHandler->handle($request);

    self::assertSame($handler, $capturedHandler);
  }

  #[Test]
  public function passes_modified_request_to_handler(): void
  {
    $capturedAttribute = null;

    $handler = new class(self::$responseFactory, $capturedAttribute) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private mixed &$capturedAttribute,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->capturedAttribute = $request->getAttribute('user_id');

        return $this->responseFactory->createResponse(200);
      }
    };

    $middleware = new class implements MiddlewareInterface {
      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        return $handler->handle($request->withAttribute('user_id', 42));
      }
    };

    $decoratingHandler = new DecoratingRequestHandler($middleware, $handler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $decoratingHandler->handle($request);

    self::assertSame(42, $capturedAttribute);
  }

  #[Test]
  public function chains_multiple_decorating_handlers(): void
  {
    $executionLog = [];

    $finalHandler = new class(self::$responseFactory, $executionLog) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array &$executionLog,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->executionLog[] = 'handler';

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

    $outerMiddleware = $createMiddleware('outer', $executionLog);
    $innerMiddleware = $createMiddleware('inner', $executionLog);

    $innerDecoratingHandler = new DecoratingRequestHandler($innerMiddleware, $finalHandler);
    $outerDecoratingHandler = new DecoratingRequestHandler($outerMiddleware, $innerDecoratingHandler);

    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $outerDecoratingHandler->handle($request);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame(
      ['outer_start', 'inner_start', 'handler', 'inner_end', 'outer_end'],
      $executionLog,
    );
  }

  #[Test]
  public function middleware_can_modify_response_headers_and_body(): void
  {
    $handler = new class(self::$responseFactory, self::$streamFactory) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        return $this->responseFactory
          ->createResponse(200)
          ->withHeader('X-Original', 'yes')
          ->withBody($this->streamFactory->createStream('original body'));
      }
    };

    $middleware = new class(self::$streamFactory) implements MiddlewareInterface {
      public function __construct(
        private StreamFactoryInterface $streamFactory,
      ) {}

      #[Override]
      #[NoDiscard]
      public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
      ): ResponseInterface {
        $response = $handler->handle($request);

        return $response
          ->withHeader('X-Decorated', 'yes')
          ->withBody($this->streamFactory->createStream('decorated body'));
      }
    };

    $decoratingHandler = new DecoratingRequestHandler($middleware, $handler);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/');
    $response = $decoratingHandler->handle($request);

    self::assertSame('yes', $response->getHeaderLine('X-Original'));
    self::assertSame('yes', $response->getHeaderLine('X-Decorated'));
    self::assertSame('decorated body', (string) $response->getBody());
  }
}
