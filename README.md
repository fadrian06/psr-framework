# PSR Framework

A lightweight, modern, and modular HTTP framework for PHP 8.3+ built on PSR-15 (HTTP Server Request Handlers & Middleware) and PSR-7 (HTTP Message Interfaces).

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Core Components](#core-components)
  - [Request Handlers & Dispatchers](#request-handlers--dispatchers)
    - [QueueRequestHandler (FIFO)](#queuerequesthandler-fifo)
    - [StackRequestHandler (LIFO)](#stackrequesthandler-lifo)
    - [DecoratingRequestHandler (Decorator)](#decoratingrequesthandler-decorator)
  - [Routing System](#routing-system)
    - [Router](#router)
    - [Result](#result)
    - [RoutingMiddleware](#routingmiddleware)
  - [Helper Functions](#helper-functions)
    - [getenv()](#getenv)
    - [send_response()](#send_response)
- [Full Application Example](#full-application-example)
- [Testing](#testing)
- [License](#license)

---

## Features

- **Standard Compliant**: Fully adheres to [PSR-7](https://www.php-fig.org/psr/psr-7/) and [PSR-15](https://www.php-fig.org/psr/psr-15/).
- **Flexible Dispatching Strategies**:
  - **Queue-based** (FIFO) pipeline using `SplQueue`.
  - **Stack-based** (LIFO) pipeline using `SplStack`.
  - **Decorator pattern** (`DecoratingRequestHandler`) for nested onion architectures.
- **Regex-Powered Router**:
  - Supports multiple HTTP methods (`GET`, `POST`, `PUT`, `DELETE`, etc.) per route.
  - Supports multiple URI patterns per handler.
  - Automatically captures named regex groups (e.g. `(?<id>\d+)`) and injects them as request attributes.
- **Utility Functions**:
  - Auto-typecasting environment variable getter (`getenv()`).
  - Native SAPI response emitter (`send_response()`).
- **PHP 8.3 Ready**: Built using strict types, readonly classes, and modern PHP attributes (`#[Override]`, `#[NoDiscard]`).

---

## Requirements

- **PHP**: `^8.3`
- **PSR Dependencies**:
  - `psr/http-server-handler`: `^1.0`
  - `psr/http-server-middleware`: `^1.0`

---

## Installation

Install via [Composer](https://getcomposer.org/):

```bash
composer require faslatam/psr-framework
```

---

## Core Components

### Request Handlers & Dispatchers

The framework provides three request handler implementations for composing and executing PSR-15 middlewares.

#### `QueueRequestHandler` (FIFO)

Executes middlewares in First-In, First-Out order. The first middleware passed to the constructor runs first.

```php
use Faslatam\PsrFramework\QueueRequestHandler;

$handler = new QueueRequestHandler(
    $fallbackHandler, // Called when all middlewares delegate or the queue is empty
    $authMiddleware,   // 1st to execute
    $loggerMiddleware, // 2nd to execute
    $routingMiddleware // 3rd to execute
);

$response = $handler->handle($serverRequest);
```

#### `StackRequestHandler` (LIFO)

Executes middlewares in Last-In, First-Out order. Middlewares are pushed onto an internal `SplStack`; the last middleware passed executes first.

```php
use Faslatam\PsrFramework\StackRequestHandler;

$handler = new StackRequestHandler(
    $fallbackHandler,
    $routingMiddleware, // 3rd to execute (pushed 1st)
    $loggerMiddleware,  // 2nd to execute (pushed 2nd)
    $authMiddleware     // 1st to execute (pushed last, popped first)
);

$response = $handler->handle($serverRequest);
```

#### `DecoratingRequestHandler` (Decorator)

Wraps a single middleware around a next handler, enabling individual decoration or nested onion chains.

```php
use Faslatam\PsrFramework\DecoratingRequestHandler;

$handler = new DecoratingRequestHandler($middleware, $nextHandler);
$response = $handler->handle($serverRequest);
```

---

### Routing System

#### `Router`

Matches an incoming PSR-7 `ServerRequestInterface` against registered HTTP methods and regular expression path patterns.

- Named regex capture groups (`(?<name>...)` or `(?P<name>...)`) are extracted as route attributes.
- Positional capture groups are ignored, ensuring clean attribute arrays.

```php
use Faslatam\PsrFramework\Router;

$router = new Router();

// Attach a route with named parameters
$router->attach(
    methods: ['GET'],
    patterns: ['#^/users/(?<id>\d+)$#'],
    handler: $userHandler
);

// Attach multiple methods and patterns to the same handler
$router->attach(
    methods: ['GET', 'POST'],
    patterns: ['#^/$#', '#^/home$#'],
    handler: $homeHandler
);

// Match request
$result = $router->match($request);

if ($result->isSuccess()) {
    $matchedHandler = $result->getHandler();
    $attributes = $result->getAttributes(); // e.g. ['id' => '42']
}
```

#### `Result`

Encapsulates the result of a route matching operation:

- `isSuccess(): bool`: Whether a route matched.
- `getHandler(): RequestHandlerInterface`: Returns the resolved handler (throws `TypeError` if called on failure).
- `getAttributes(): array`: Key-value array of captured named parameters.

```php
use Faslatam\PsrFramework\Result;

// Successful match
$result = Result::success($handler, id: '123', section: 'details');

// Unmatched route
$failure = Result::failure();
```

#### `RoutingMiddleware`

A PSR-15 middleware that integrates `Router` into the middleware pipeline.

- When a route matches, it replaces the handler with the matched route handler and injects all captured parameters into the request using `$request->withAttribute($name, $value)`.
- When no route matches, execution falls through to the original `$handler` (such as a 404 Not Found fallback).

```php
use Faslatam\PsrFramework\RoutingMiddleware;

$routingMiddleware = new RoutingMiddleware($router);
```

---

### Helper Functions

The framework provides utility functions under the `Faslatam\PsrFramework` namespace:

#### `getenv()`

Reads a variable from `$_ENV` and automatically casts it to its appropriate native PHP type:

- **Integers**: `'100'` -> `100`
- **Floats**: `'3.14'` -> `3.14`
- **Booleans**: `'true'`, `'on'`, `true` -> `true` | `'false'`, `'off'`, `false` -> `false`
- **Strings**: Unmodified string value
- **Missing key**: Returns `null`

```php
use function Faslatam\PsrFramework\getenv;

$debug = getenv('APP_DEBUG');       // bool
$port  = getenv('PORT');            // int
$env   = getenv('APP_ENV');         // string
$unset = getenv('NON_EXISTING');    // null
```

#### `send_response()`

Outputs a PSR-7 `ResponseInterface` to the client:

1. Emits the HTTP status code via `http_response_code()`.
2. Sends all HTTP headers using `header()`.
3. Streams the response body to standard output.

```php
use function Faslatam\PsrFramework\send_response;

send_response($response);
```

---

## Full Application Example

Here is a complete example combining routing, middleware, and request handling with [Guzzle PSR-7](https://github.com/guzzle/psr7):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Faslatam\PsrFramework\QueueRequestHandler;
use Faslatam\PsrFramework\Router;
use Faslatam\PsrFramework\RoutingMiddleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Faslatam\PsrFramework\send_response;

$httpFactory = new HttpFactory();

// 1. Define Route Handlers
$homeHandler = new class($httpFactory) implements RequestHandlerInterface {
    public function __construct(private HttpFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->factory->createResponse(200);
        $response->getBody()->write('Welcome to the homepage!');
        return $response;
    }
};

$userHandler = new class($httpFactory) implements RequestHandlerInterface {
    public function __construct(private HttpFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $request->getAttribute('id');
        $response = $this->factory->createResponse(200);
        $response->getBody()->write("User ID: {$userId}");
        return $response;
    }
};

// 2. Define 404 Fallback Handler
$notFoundHandler = new class($httpFactory) implements RequestHandlerInterface {
    public function __construct(private HttpFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->factory->createResponse(404);
        $response->getBody()->write('404 Not Found');
        return $response;
    }
};

// 3. Configure Router
$router = new Router();
$router->attach(['GET'], ['#^/$#'], $homeHandler);
$router->attach(['GET'], ['#^/users/(?<id>\d+)$#'], $userHandler);

// 4. Build Pipeline
$app = new QueueRequestHandler(
    $notFoundHandler,
    new RoutingMiddleware($router)
);

// 5. Handle Request and Send Response
$request = ServerRequest::fromGlobals();
$response = $app->handle($request);

send_response($response);
```

---

## Testing

The test suite is written using [PHPUnit](https://phpunit.de/):

```bash
vendor/bin/phpunit
```

Run linter checks using [Mago](https://mago.carthage.software/):

```bash
vendor/bin/mago lint
```

---

## License

This project is open-source software licensed under the MIT License.
