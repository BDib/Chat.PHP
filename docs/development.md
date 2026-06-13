# Development & Extension Guide

Welcome to the internal architecture guide for Modernized PHP Chat.

## Architecture Overview

The project follows a modular PSR-4 structure:

- `src/Config.php`: Entry point for all configuration. Handles `.env` loading and sensible defaults.
- `src/Database.php`: Singleton-like access to PDO. Handles multi-driver support and automatic schema initialization.
- `src/Auth/`: Core identity logic. `SessionManager` handles tokens/CSRF, `Authenticator` handles user data, security policies, and throttling.
- `src/Chat/`: Business logic. `ChatService` is the data access layer for messages, while `CommandHandler` parses `/` commands.
- `src/Controller/`: Request handlers. `ApiController` for JSON logic, `ViewController` for HTML rendering, `AdminController` for dashboard actions, and `ProfileController` for user settings.
- `views/`: Plain PHP templates. Using `extract()` in the controller ensures a clean scope.
- `lang/`: JSON translation files for internationalization.

## How to...

### 1. Add a new Chat Command
Commands are defined as separate classes in `src/Chat/Commands/`.

1.  Create a new class extending `AbstractCommand`.
2.  Register it in `src/Chat/CommandHandler.php`.

Example:
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

### 2. Add a new Translation
1.  Create a new JSON file in `lang/` (e.g., `it.json` for Italian).
2.  Copy the keys from `en.json` and provide the translations.
3.  Users can switch to this language by setting `APP_LANG=it` in their `.env`.

### 3. Handle RTL Layouts
If adding an RTL language (like Hebrew or Farsi):
1.  Add the language code to the `$rtlLangs` array in `src/Translator.php`.
2.  The layout will automatically switch to `dir="rtl"`.
3.  Add any necessary RTL CSS overrides in `public/assets/css/style.css` using the `[dir="rtl"]` selector.

### 4. Add a new Database Driver
The database logic is centralized in `src/Database.php`.

1.  Update `getDSN()` to support your new driver.
2.  Check `getSchema()` for any SQL dialect differences (autoincrement, types, primary keys).
3.  Update the migration logic in `MigrationManager` if needed.

## Running Tests

We use PHPUnit for unit testing.
```bash
./vendor/bin/phpunit
```
Tests are located in the `tests/` directory and should be added for any new business logic.
