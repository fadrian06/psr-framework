<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Override;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DecoratingRequestHandler implements RequestHandlerInterface
{
  public function __construct(
    private MiddlewareInterface $middleware,
    private RequestHandlerInterface $nextHandler,
  ) {}

  #[Override]
  #[NoDiscard]
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return $this->middleware->process($request, $this->nextHandler);
  }
}
