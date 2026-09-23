<?php

declare(strict_types=1);

use Faslatam\PsrFramework\Router;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouterTest extends TestCase
{
  private static ServerRequestFactoryInterface $serverRequestFactory;

  #[Override]
  public static function setUpBeforeClass(): void
  {
    parent::setUpBeforeClass();

    self::$serverRequestFactory = new HttpFactory;
  }

  private function createDummyHandler(): RequestHandlerInterface
  {
    return new class implements RequestHandlerInterface {
      #[Override]
      #[NoDiscard]
      public function handle(
        ServerRequestInterface $request,
      ): ResponseInterface {
        throw new LogicException('Not implemented');
      }
    };
  }

  #[Test]
  public function returns_failure_when_no_routes_attached(): void
  {
    $router = new Router;
    $request = self::$serverRequestFactory->createServerRequest('GET', '/users');
    $result = $router->match($request);

    self::assertFalse($result->isSuccess());
  }

  #[Test]
  public function returns_failure_when_method_is_not_registered(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(['GET'], ['#^/users$#'], $handler);

    $request = self::$serverRequestFactory->createServerRequest('POST', '/users');
    $result = $router->match($request);

    self::assertFalse($result->isSuccess());
  }

  #[Test]
  public function returns_failure_when_path_does_not_match_any_pattern(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(['GET'], ['#^/users$#'], $handler);

    $request = self::$serverRequestFactory->createServerRequest('GET', '/products');
    $result = $router->match($request);

    self::assertFalse($result->isSuccess());
  }

  #[Test]
  public function matches_single_route_successfully(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(['GET'], ['#^/users$#'], $handler);

    $request = self::$serverRequestFactory->createServerRequest('GET', '/users');
    $result = $router->match($request);

    self::assertTrue($result->isSuccess());
    self::assertSame($handler, $result->getHandler());
    self::assertSame([], $result->getAttributes());
  }

  #[Test]
  public function extracts_named_parameters_as_attributes(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(
      ['GET'],
      ['#^/users/(?<id>\d+)/posts/(?<slug>[a-z-]+)$#'],
      $handler,
    );

    $request = self::$serverRequestFactory->createServerRequest(
      'GET',
      '/users/42/posts/hello-world',
    );
    $result = $router->match($request);

    self::assertTrue($result->isSuccess());
    self::assertSame($handler, $result->getHandler());
    self::assertSame(['id' => '42', 'slug' => 'hello-world'], $result->getAttributes());
  }

  #[Test]
  public function ignores_numeric_capture_group_keys(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(
      ['GET'],
      ['#^/items/(\d+)/(?<name>[a-z]+)$#'],
      $handler,
    );

    $request = self::$serverRequestFactory->createServerRequest(
      'GET',
      '/items/100/laptop',
    );
    $result = $router->match($request);

    self::assertTrue($result->isSuccess());
    self::assertSame(['name' => 'laptop'], $result->getAttributes());
  }

  #[Test]
  public function supports_multiple_http_methods_in_attach(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(['GET', 'POST', 'PATCH'], ['#^/articles$#'], $handler);

    $getRequest = self::$serverRequestFactory->createServerRequest('GET', '/articles');
    $postRequest = self::$serverRequestFactory->createServerRequest('POST', '/articles');
    $patchRequest = self::$serverRequestFactory->createServerRequest('PATCH', '/articles');
    $deleteRequest = self::$serverRequestFactory->createServerRequest('DELETE', '/articles');

    self::assertTrue($router->match($getRequest)->isSuccess());
    self::assertTrue($router->match($postRequest)->isSuccess());
    self::assertTrue($router->match($patchRequest)->isSuccess());
    self::assertFalse($router->match($deleteRequest)->isSuccess());
  }

  #[Test]
  public function supports_multiple_path_patterns_in_attach(): void
  {
    $router = new Router;
    $handler = $this->createDummyHandler();
    $router->attach(['GET'], ['#^/$#', '#^/home$#', '#^/index$#'], $handler);

    $rootRequest = self::$serverRequestFactory->createServerRequest('GET', '/');
    $homeRequest = self::$serverRequestFactory->createServerRequest('GET', '/home');
    $indexRequest = self::$serverRequestFactory->createServerRequest('GET', '/index');
    $aboutRequest = self::$serverRequestFactory->createServerRequest('GET', '/about');

    self::assertTrue($router->match($rootRequest)->isSuccess());
    self::assertTrue($router->match($homeRequest)->isSuccess());
    self::assertTrue($router->match($indexRequest)->isSuccess());
    self::assertFalse($router->match($aboutRequest)->isSuccess());
  }

  #[Test]
  public function supports_multiple_attach_calls_for_same_method(): void
  {
    $router = new Router;
    $usersHandler = $this->createDummyHandler();
    $postsHandler = $this->createDummyHandler();

    $router->attach(['GET'], ['#^/users$#'], $usersHandler);
    $router->attach(['GET'], ['#^/posts$#'], $postsHandler);

    $usersRequest = self::$serverRequestFactory->createServerRequest('GET', '/users');
    $postsRequest = self::$serverRequestFactory->createServerRequest('GET', '/posts');

    $usersResult = $router->match($usersRequest);
    $postsResult = $router->match($postsRequest);

    self::assertTrue($usersResult->isSuccess());
    self::assertSame($usersHandler, $usersResult->getHandler());

    self::assertTrue($postsResult->isSuccess());
    self::assertSame($postsHandler, $postsResult->getHandler());
  }

  #[Test]
  public function matches_first_registered_pattern_when_multiple_patterns_match(): void
  {
    $router = new Router;
    $specificHandler = $this->createDummyHandler();
    $genericHandler = $this->createDummyHandler();

    $router->attach(['GET'], ['#^/posts/latest$#'], $specificHandler);
    $router->attach(['GET'], ['#^/posts/(?<slug>[a-z-]+)$#'], $genericHandler);

    $request = self::$serverRequestFactory->createServerRequest('GET', '/posts/latest');
    $result = $router->match($request);

    self::assertTrue($result->isSuccess());
    self::assertSame($specificHandler, $result->getHandler());
    self::assertSame([], $result->getAttributes());
  }
}
