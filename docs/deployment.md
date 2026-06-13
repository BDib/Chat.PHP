# Deployment Guide

This guide covers deploying Modernized PHP Chat to production environments.

## Prerequisite
- PHP 8.3+
- PDO Extensions (sqlite, mysql, or pgsql)
- Composer

## 1. Zero-Config Deployment (SQLite)

The easiest way to run the app. SQLite is the default driver.

1.  Upload files to your server.
2.  Ensure `db/` and `words.txt` are writable by the web server user (e.g., `www-data`).
3.  Run `composer install --no-dev`.
4.  Configure your web server to serve the `public/` directory as the document root.

## 2. Standard Production Deployment (Nginx + PHP-FPM)

### Directory Structure
It is highly recommended to place the application root *outside* of the public web folder, and only point Nginx to the `public/` subdirectory.

```bash
/var/www/chat/
  ├── src/
  ├── views/
  ├── public/ (Nginx Root)
  ├── db/
  └── .env
```

### Nginx Config
```nginx
server {
    listen 80;
    server_name chat.yourdomain.com;
    root /var/www/chat/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    }

    # Deny access to .env and other hidden files
    location ~ /\. {
        deny all;
    }
}
```

## 3. Database Configuration (MySQL / PostgreSQL)

1. Create a database and user on your database server.
2. Copy `.env.example` to `.env`.
3. Set `DB_DRIVER=mysql` or `DB_DRIVER=pgsql`.
4. Fill in `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`.
5. The application will create the schema on the first connection.

## 4. File Permissions

Modernized PHP Chat needs to write to:
- The `db/` directory (if using SQLite).
- The `words.txt` file (if edited via Admin Panel).

```bash
chown -R www-data:www-data /var/www/chat/db
chown www-data:www-data /var/www/chat/words.txt
```

## 5. Security Checklist

- [ ] Disable `display_errors` in `php.ini`.
- [ ] Set `APP_ENV=production` in `.env`.
- [ ] Use HTTPS (SSL) to protect session cookies and chat data.
- [ ] Regularly backup the `db/` directory or your external database.
