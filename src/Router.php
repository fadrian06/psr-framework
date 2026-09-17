<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Router
{
  private array $routes = [];

  public function attach(
    array $methods,
    array $patterns,
    RequestHandlerInterface $handler,
  ): void {
    foreach ($methods as $method) {
      foreach ($patterns as $pattern) {
        $this->routes[$method][$pattern] = $handler;
      }
    }
  }

  public function match(ServerRequestInterface $request): Result
  {
    if (key_exists($request->getMethod(), $this->routes)) {
      foreach ($this->routes[$request->getMethod()] as $pattern => $handler) {
        $match = preg_match($pattern, $request->getUri()->getPath(), $matches);

        if ($match) {
          $attributes = [];

          foreach ($matches as $name => $value) {
            if (is_string($name)) {
              $attributes[$name] = $value;
            }
          }

          return Result::success($handler, ...$attributes);
        }
      }
    }

    return Result::failure();
  }
}
