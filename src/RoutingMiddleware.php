<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use Faslatam\PsrFramework\Router;
use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Override;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RoutingMiddleware implements MiddlewareInterface
{
  public function __construct(private Router $router) {}

  #[Override]
  #[NoDiscard]
  public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $handler,
  ): ResponseInterface {
    $result = $this->router->match($request);

    if ($result->isSuccess()) {
      $handler = $result->getHandler();

      foreach ($result->getAttributes() as $name => $value) {
        $request = $request->withAttribute($name, $value);
      }
    }

    return $handler->handle($request);
  }
}
