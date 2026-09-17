<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use Psr\Http\Message\ResponseInterface;

function sendResponse(ResponseInterface $response): void
{
  http_response_code($response->getStatusCode());

  foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
      header("$name: $value");
    }
  }

  echo $response->getBody();
}
