# Modernized PHP Chat

A real-time chat application modernized and refactored from a monolithic script into a clean, PSR-4 compliant architecture with multi-database support and an admin dashboard.

## Features

- **PSR-4 Autoloading**: Clean class structure under the `App\` namespace.
- **Multi-Database Support**: Works with **SQLite**, **MySQL/MariaDB**, and **PostgreSQL**.
- **Admin Dashboard**: Manage users (roles, ranks, bans) and allowed words list directly from the UI.
- **Environment Configuration**: Manage settings via `.env` file.
- **Modern PHP**: Utilizes PHP 8.3 features for better performance and readability.
- **Advanced Chat Features**:
  - Rank-based permissions (0-9).
  - Word filtering and pronounceable username validation.
  - Rate limiting and cooldowns.
  - Image (GIF) uploads.
  - Rich text effects and Admin/Moderator commands.

## Requirements

- PHP 8.3 or higher
- PDO Extensions (sqlite, mysql, or pgsql)
- Composer

## Installation

1.  **Clone the repository**.
2.  **Install dependencies**:
    ```bash
    composer install
    ```
3.  **Configure environment**:
    ```bash
    cp .env.example .env
    # Edit .env to set your DB driver and credentials
    ```
4.  **Start the server**:
    ```bash
    php -S localhost:8000 -t public
    ```

## Production Deployment

### Nginx Configuration
Create a virtual host pointing to the `public/` directory:
```nginx
server {
    listen 80;
    server_name chat.example.com;
    root /var/www/chat/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    }
}
```

### Database Migration
The application handles schema initialization automatically on the first connection. Ensure the database user has `CREATE TABLE` and `INDEX` permissions.

## Extending the Application

### Adding New Commands
To add a new chat command (e.g., `/mycmd`), edit `src/Chat/CommandHandler.php`:
1.  Add the pattern and handler logic to the `getCommands()` method.
2.  Define required roles and usage instructions.

### Customizing Styles
Edit `public/assets/css/style.css` to change the look and feel. The UI uses CSS variables for easy theming.

## License

MIT License - Copyright (c) 2026 B Dib. See [LICENSE](LICENSE) for details.
