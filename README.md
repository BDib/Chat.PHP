# Modernized PHP Chat

A real-time chat application modernized and refactored from a monolithic script into a clean, PSR-4 compliant architecture with multi-database support and an admin dashboard.

## Quick Start (Zero-Config)

The application defaults to **SQLite**, allowing you to start chatting immediately without manual configuration.

1.  **Install dependencies**:
    ```bash
    composer install
    ```
2.  **Start the server**:
    ```bash
    php -S localhost:8000 -t public
    ```
3.  **Chat**: Open `http://localhost:8000`. The first registered user automatically becomes the **Admin**.

## Key Features

- **Modern PSR-4 Architecture**: Clean, maintainable code using PHP 8.3 features.
- **Multi-Database Support**: Out-of-the-box support for SQLite, MySQL/MariaDB, and PostgreSQL.
- **Admin Dashboard**: Web-based interface for user moderation and word filtering.
- **Dynamic Ranks**: 0-9 rank system with various feature unlocks.
- **Rich Interaction**: Replies, GIF uploads, dice rolling, and animated text effects.

## Extensive Documentation

We've provided detailed guides for every aspect of the application:

- [**Application Features & UX**](docs/features.md) - Deep dive into the user experience and rank system.
- [**Usage & Tutorial**](docs/usage.md) - Step-by-step guide for users and administrators.
- [**Deployment Guide**](docs/deployment.md) - How to host in production (Nginx, MySQL, etc.).
- [**Development & Extension**](docs/development.md) - Architecture overview and how to add new features.

## Requirements

- PHP 8.3 or higher
- PDO Extensions (sqlite, mysql, or pgsql)
- Composer

## License

MIT License - Copyright (c) 2026 B Dib. See [LICENSE](LICENSE) for details.
