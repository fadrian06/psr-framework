<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Faslatam\PsrFramework\getenv;

final class GetEnvTest extends TestCase
{
  #[Test]
  public function return_null_for_unknown_key(): void
  {
    $unknownKey = uniqid();
    $env = getenv($unknownKey);

    self::assertNull($env);
  }

  #[Test]
  public function return_string_for_string_key(): void
  {
    $_ENV['A_STRING_ENV'] = 'a string';
    $env = getenv('A_STRING_ENV');

    self::assertSame('a string', $env);
  }

  #[Test]
  public function return_int_for_int_key(): void
  {
    $_ENV['A_INT_KEY'] = 1;
    $env = getenv('A_INT_KEY');

    self::assertSame(1, $env);
  }

  #[Test]
  public function return_int_for_numeric_string_key(): void
  {
    $_ENV['A_INT_KEY'] = '1';
    $env = getenv('A_INT_KEY');

    self::assertSame(1, $env);
  }

  #[Test]
  public function return_float_for_float_key(): void
  {
    $_ENV['A_FLOAT_KEY'] = 1.1;
    $env = getenv('A_FLOAT_KEY');

    self::assertSame(1.1, $env);
  }

  #[Test]
  public function return_float_for_float_numeric_key(): void
  {
    $_ENV['A_FLOAT_KEY'] = '1.1';
    $env = getenv('A_FLOAT_KEY');

    self::assertSame(1.1, $env);
  }

  #[Test]
  public function return_true_for_true_key(): void
  {
    $_ENV['A_TRUE_KEY'] = true;
    $env = getenv('A_TRUE_KEY');

    self::assertTrue($env);
  }

  #[Test]
  #[DataProvider('truthyKeys')]
  public function return_true_for_truthy_key(string $truthyKey): void
  {
    $_ENV['A_TRUE_KEY'] = $truthyKey;
    $env = getenv('A_TRUE_KEY');

    self::assertTrue($env);
  }

  #[Test]
  public function return_false_for_false_key(): void
  {
    $_ENV['A_FALSE_KEY'] = false;
    $env = getenv('A_FALSE_KEY');

    self::assertFalse($env);
  }

  #[Test]
  #[DataProvider('falsyKeys')]
  public function return_false_for_falsy_key(string $falsyKey): void
  {
    $_ENV['A_FALSE_KEY'] = $falsyKey;
    $env = getenv('A_FALSE_KEY');

    self::assertFalse($env);
  }

  public static function truthyKeys(): array
  {
    return [
      ['on'],
      ['On'],
      ['true'],
    ];
  }

  public static function falsyKeys(): array
  {
    return [
      ['off'],
      ['Off'],
      ['false'],
    ];
  }
}
