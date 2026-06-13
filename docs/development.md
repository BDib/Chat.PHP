# Development & Extension Guide

Welcome to the internal architecture guide for Modernized PHP Chat.

## Architecture Overview

The project follows a modular PSR-4 structure:

- `src/Config.php`: Entry point for all configuration. Handles `.env` loading and sensible defaults.
- `src/Database.php`: Singleton-like access to PDO. Handles multi-driver support and automatic schema initialization.
- `src/Auth/`: Core identity logic. `SessionManager` handles tokens/CSRF, `Authenticator` handles user data.
- `src/Chat/`: Business logic. `ChatService` is the data access layer for messages, while `CommandHandler` parses `/` commands.
- `src/Controller/`: Request handlers. `ApiController` for JSON logic, `ViewController` for HTML rendering, and `AdminController` for dashboard actions.
- `views/`: Plain PHP templates. Using `extract()` in the controller ensures a clean scope.

## How to...

### 1. Add a new Chat Command
Commands are defined in `src/Chat/CommandHandler.php`.

1.  Navigate to the `getCommands()` method.
2.  Add a new entry to the array:
    ```php
    'echo' => [
        'pattern' => '#^/echo (.+)$#',
        'roles' => ['user', 'moderator', 'admin'],
        'usage' => '/echo <text>',
        'handler' => function ($text) {
            $this->chatService->systemMessage("Echo: $text", $this->currentUser->username, $this->ip);
        }
    ],
    ```

### 2. Add a new Database Driver
The database logic is centralized in `src/Database.php`.

1.  Update `getDSN()` to support your new driver.
2.  Check `getSchema()` for any SQL dialect differences (autoincrement, types).
3.  Add the driver to the `.env.example` documentation.

### 3. Customize the Frontend
The frontend is built with vanilla JS and CSS.
- **Styles**: `public/assets/css/style.css`. Uses CSS variables for themes.
- **Logic**: `public/assets/js/app.js`. Communicates with `?api=...` endpoints.

## Running Tests

We use PHPUnit for unit testing.
```bash
./vendor/bin/phpunit
```
Tests are located in the `tests/` directory and should be added for any new business logic.
