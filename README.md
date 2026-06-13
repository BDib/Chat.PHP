# Modernized PHP Chat

A real-time chat application modernized and refactored from a monolithic script into a clean, PSR-4 compliant architecture.

## Features

- **PSR-4 Autoloading**: Clean class structure under the `App\` namespace.
- **MVC-ish Architecture**: Separation of concerns between controllers, services, and views.
- **Modern PHP**: Utilizes PHP 8.3 features for better performance and readability.
- **SQLite Database**: Self-contained database logic with automatic schema initialization.
- **Asset Separation**: CSS and JavaScript are separated into their own files for better maintainability.
- **Composer Support**: Integrated with Composer for autoloading and future dependency management.
- **Advanced Chat Features**:
  - Rank-based permissions (0-9).
  - Word filtering and pronounceable username validation.
  - Rate limiting and cooldowns.
  - Image (GIF) uploads.
  - Rich text effects (wave, fire, cold breeze, gradients).
  - Admin/Moderator commands (/ban, /kick, /mute, /world, etc.).

## Requirements

- PHP 8.3 or higher
- SQLite3 extension enabled

## Installation

1. Clone the repository.
2. Install dependencies (autoloader):
   ```bash
   composer install
   ```
3. Start the built-in PHP server:
   ```bash
   php -S localhost:8000 -t public
   ```
4. Open `http://localhost:8000` in your browser.

## Project Structure

- `src/`: Core logic (Models, Services, Controllers).
  - `Auth/`: Authentication and Session management.
  - `Chat/`: Chat logic, command handling, and word filtering.
  - `Controller/`: API and View routing.
- `public/`: Web root.
  - `assets/`: CSS and JS files.
  - `index.php`: Entry point.
- `views/`: HTML templates.
- `db/`: SQLite database files (created automatically).
- `words.txt`: Dictionary for word filtering.

## License

MIT License - Copyright (c) 2026 B Dib. See [LICENSE](LICENSE) for details.
