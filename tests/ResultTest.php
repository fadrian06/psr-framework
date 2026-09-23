<?php

declare(strict_types=1);

use Faslatam\PsrFramework\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResultTest extends TestCase
{
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
  public function creates_successful_result_without_attributes(): void
  {
    $handler = $this->createDummyHandler();
    $result = Result::success($handler);

    self::assertTrue($result->isSuccess());
    self::assertSame($handler, $result->getHandler());
    self::assertSame([], $result->getAttributes());
  }

  #[Test]
  public function creates_successful_result_with_attributes(): void
  {
    $handler = $this->createDummyHandler();
    $attributes = ['id' => '42', 'slug' => 'psr-framework'];
    $result = Result::success($handler, ...$attributes);

    self::assertTrue($result->isSuccess());
    self::assertSame($handler, $result->getHandler());
    self::assertSame($attributes, $result->getAttributes());
  }

  #[Test]
  public function creates_failure_result(): void
  {
    $result = Result::failure();

    self::assertFalse($result->isSuccess());
    self::assertSame([], $result->getAttributes());
  }

  #[Test]
  public function get_handler_throws_type_error_on_failure(): void
  {
    $result = Result::failure();

    $this->expectException(TypeError::class);
    $result->getHandler();
  }
}
