<?php

declare(strict_types=1);

use Faslatam\PsrFramework\DecoratingRequestHandler;
use Faslatam\PsrFramework\Router;
use Faslatam\PsrFramework\RoutingMiddleware;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RoutingMiddlewareTest extends TestCase
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
  public function delegates_to_fallback_handler_when_route_does_not_match(): void
  {
    $router = new Router;

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

        return $this->responseFactory->createResponse(404);
      }
    };

    $middleware = new RoutingMiddleware($router);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/not-found');
    $response = $middleware->process($request, $fallbackHandler);

    self::assertTrue($fallbackHandler->called);
    self::assertSame(404, $response->getStatusCode());
  }

  #[Test]
  public function delegates_to_matched_route_handler_when_route_matches(): void
  {
    $router = new Router;

    $routeHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

        return $this->responseFactory->createResponse(404);
      }
    };

    $router->attach(['GET'], ['#^/profile$#'], $routeHandler);

    $middleware = new RoutingMiddleware($router);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/profile');
    $response = $middleware->process($request, $fallbackHandler);

    self::assertTrue($routeHandler->called);
    self::assertFalse($fallbackHandler->called);
    self::assertSame(200, $response->getStatusCode());
  }

  #[Test]
  public function injects_route_attributes_into_request_for_matched_handler(): void
  {
    $router = new Router;
    $capturedAttributes = [];

    $routeHandler = new class(self::$responseFactory, $capturedAttributes) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array &$capturedAttributes,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->capturedAttributes = [
          'category' => $request->getAttribute('category'),
          'id' => $request->getAttribute('id'),
        ];

        return $this->responseFactory->createResponse(200);
      }
    };

    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

    $router->attach(
      ['GET'],
      ['#^/catalog/(?<category>[a-z]+)/(?<id>\d+)$#'],
      $routeHandler,
    );

    $middleware = new RoutingMiddleware($router);
    $request = self::$serverRequestFactory->createServerRequest('GET', '/catalog/electronics/789');
    $response = $middleware->process($request, $fallbackHandler);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame(
      ['category' => 'electronics', 'id' => '789'],
      $capturedAttributes,
    );
  }

  #[Test]
  public function preserves_existing_request_attributes_when_injecting_route_attributes(): void
  {
    $router = new Router;
    $capturedAttributes = [];

    $routeHandler = new class(self::$responseFactory, $capturedAttributes) implements RequestHandlerInterface {
      public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array &$capturedAttributes,
      ) {}

      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        $this->capturedAttributes = [
          'user_role' => $request->getAttribute('user_role'),
          'slug' => $request->getAttribute('slug'),
        ];

        return $this->responseFactory->createResponse(200);
      }
    };

    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

    $router->attach(['GET'], ['#^/blog/(?<slug>[a-z-]+)$#'], $routeHandler);

    $middleware = new RoutingMiddleware($router);
    $request = self::$serverRequestFactory
      ->createServerRequest('GET', '/blog/welcome-post')
      ->withAttribute('user_role', 'admin');

    $response = $middleware->process($request, $fallbackHandler);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame(
      ['user_role' => 'admin', 'slug' => 'welcome-post'],
      $capturedAttributes,
    );
  }

  #[Test]
  public function works_in_pipeline_with_decorating_request_handler(): void
  {
    $router = new Router;

    $routeHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

    $fallbackHandler = new class(self::$responseFactory) implements RequestHandlerInterface {
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

    $router->attach(['GET'], ['#^/api/health$#'], $routeHandler);

    $routingMiddleware = new RoutingMiddleware($router);
    $decoratingHandler = new DecoratingRequestHandler($routingMiddleware, $fallbackHandler);

    // Matched route
    $matchedRequest = self::$serverRequestFactory->createServerRequest('GET', '/api/health');
    $matchedResponse = $decoratingHandler->handle($matchedRequest);
    self::assertSame(200, $matchedResponse->getStatusCode());

    // Unmatched route falls back to 404
    $unmatchedRequest = self::$serverRequestFactory->createServerRequest('GET', '/api/other');
    $unmatchedResponse = $decoratingHandler->handle($unmatchedRequest);
    self::assertSame(404, $unmatchedResponse->getStatusCode());
  }
}
