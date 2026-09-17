<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Override;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SplQueue;

final readonly class QueueRequestHandler implements RequestHandlerInterface
{
  private SplQueue $middlewares;

  public function __construct(
    private RequestHandlerInterface $fallbackHandler,
    MiddlewareInterface ...$middlewares,
  ) {
    $this->middlewares = new SplQueue;
    $this->middlewares->setIteratorMode(SplQueue::IT_MODE_DELETE);

    foreach ($middlewares as $middleware) {
      $this->middlewares->enqueue($middleware);
    }
  }

  #[Override]
  #[NoDiscard]
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return $this->middlewares->isEmpty()
      ? $this->fallbackHandler->handle($request)
      : $this->middlewares->dequeue()->process($request, $this);
  }
}
