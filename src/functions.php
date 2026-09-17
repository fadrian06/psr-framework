<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use Psr\Http\Message\ResponseInterface;

function getenv(string $name): null|int|float|string|bool
{
  $env = $_ENV[$name] ?? null;

  if ($filteredVar = filter_var($env, FILTER_VALIDATE_BOOL)) {
    return $filteredVar;
  }

  if ($filteredVar = filter_var($env, FILTER_VALIDATE_INT)) {
    return $filteredVar;
  }

  if ($filteredVar = filter_var($env, FILTER_VALIDATE_FLOAT)) {
    return $filteredVar;
  }

  if (is_string($env)) {
    return $env;
  }

  return null;
}

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
