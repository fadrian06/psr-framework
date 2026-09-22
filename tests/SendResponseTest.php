<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function Faslatam\PsrFramework\send_response;

final class SendResponseTest extends TestCase
{
  private static ResponseFactoryInterface $responseFactory;
  private static StreamFactoryInterface $streamFactory;

  #[Override]
  public static function setUpBeforeClass(): void
  {
    parent::setUpBeforeClass();

    self::$responseFactory = new HttpFactory;
    self::$streamFactory = new HttpFactory;
  }

  #[Test]
  public function set_status_code(): void
  {
    $response = self::$responseFactory->createResponse(404);
    send_response($response);
    $code = http_response_code();

    self::assertSame(404, $code);
  }

  #[Test]
  public function prints_body(): void
  {
    $content = uniqid();

    $response = self::$responseFactory
      ->createResponse()
      ->withBody(self::$streamFactory->createStream($content));

    self::expectOutputString($content);

    send_response($response);
  }
}
