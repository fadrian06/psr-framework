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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DecoratingRequestHandlerTest extends TestCase
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

    self::assertSame(403, $response->getStatusCode());
  }
}
