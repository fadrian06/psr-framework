<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Override;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SplStack;

final readonly class StackRequestHandler implements RequestHandlerInterface
{
  private SplStack $middlewares;

  public function __construct(
    private RequestHandlerInterface $fallbackHandler,
    MiddlewareInterface ...$middlewares,
  ) {
    $this->middlewares = new SplStack;

    foreach ($middlewares as $middleware) {
      $this->middlewares->push($middleware);
    }
  }

  #[Override]
  #[NoDiscard]
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return $this->middlewares->isEmpty()
      ? $this->fallbackHandler->handle($request)
      : $this->middlewares->pop()->process($request, $this);
  }
}
