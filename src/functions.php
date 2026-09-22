<?php

declare(strict_types=1);

namespace Faslatam\PsrFramework;

use Psr\Http\Message\ResponseInterface;

function getenv(string $name): null|int|float|string|bool
{
  if (!array_key_exists($name, $_ENV)) {
    return null;
  }

  $env = $_ENV[$name];

  if (is_bool($env)) {
    return $env;
  }

  static $filters = [
    FILTER_VALIDATE_INT,
    FILTER_VALIDATE_FLOAT,
    FILTER_VALIDATE_BOOL,
  ];

  foreach ($filters as $filter) {
    $filteredVar = filter_var($env, $filter, FILTER_NULL_ON_FAILURE);

    if ($filteredVar !== null) {
      if (is_int($filteredVar)) {
        return $filteredVar;
      }

      if (is_float($filteredVar)) {
        return $filteredVar;
      }

      if (is_bool($filteredVar)) {
        return $filteredVar;
      }
    }
  }

  return $env;
}

function send_response(ResponseInterface $response): void
{
  http_response_code($response->getStatusCode());

  foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
      header("{$name}: {$value}");
    }
  }

  echo (string) $response->getBody();
}
