# Modernized PHP Chat

A real-time chat application modernized and refactored from a monolithic script into a clean, PSR-4 compliant architecture with multi-database support, global translations, and an admin dashboard.

## Quick Start (Docker)

The fastest way to get running is with Docker:
```bash
docker compose up -d
```
Open `http://localhost:8000`.

## Quick Start (Zero-Config Manual)

The application defaults to **SQLite**, allowing you to start chatting immediately without manual configuration.

1.  **Install dependencies**:
    ```bash
    composer install
    ```
2.  **Start the server**:
    ```bash
    php -S localhost:8000 -t public
    ```
3.  **Chat**: Open `http://localhost:8000`. The first user to register will automatically become the **Admin**.

## Key Features

- **Modern PSR-4 Architecture**: Clean, maintainable code using PHP 8.3 features.
- **Multi-Database Support**: Out-of-the-box support for SQLite, MySQL/MariaDB, and PostgreSQL.
- **Global & RTL Support**: Translated into 10 languages, including full Right-to-Left (RTL) layout support.
- **Admin Dashboard**: Web-based interface for user moderation and word filtering.
- **Dynamic Ranks**: 0-9 rank system with various feature unlocks.
- **Containerized**: Full Docker and Docker Compose support.

## Languages Supported
- English (en)
- Spanish (es)
- German (de)
- French (fr)
- Russian (ru)
- Hindi (hi)
- Mandarin Chinese (zh)
- Japanese (ja)
- Korean (ko)
- Arabic (ar - RTL)

## Extensive Documentation

We've provided detailed guides for every aspect of the application:

- [**Application Features & UX**](docs/features.md) - Deep dive into the user experience, rank system, and RTL support.
- [**Usage & Tutorial**](docs/usage.md) - Step-by-step guide for users and administrators.
- [**Deployment Guide**](docs/deployment.md) - Detailed instructions for Docker, Nginx, and Database setup.
- [**Development & Extension**](docs/development.md) - Architecture overview and how to add new features or translations.

## Requirements

- PHP 8.3 or higher
- PDO Extensions (sqlite, mysql, or pgsql)
- Composer

## License

MIT License - Copyright (c) 2026 B Dib. See [LICENSE](LICENSE) for details.
