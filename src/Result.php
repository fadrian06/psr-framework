<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use Psr\Http\Server\RequestHandlerInterface;

final class Result
{
  private array $attributes = [];

  private function __construct(
    private ?RequestHandlerInterface $handler = null,
    string ...$attributes,
  ) {
    $this->attributes = $attributes;
  }

  public static function success(
    RequestHandlerInterface $handler,
    string ...$attributes
  ): self {
    return new self($handler, ...$attributes);
  }

  public static function failure(): self
  {
    return new self;
  }

  public function isSuccess(): bool
  {
    return $this->handler instanceof RequestHandlerInterface;
  }

  public function getHandler(): RequestHandlerInterface
  {
    return $this->handler;
  }

  public function getAttributes(): array
  {
    return $this->attributes;
  }
}
