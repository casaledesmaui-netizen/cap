FROM dunglas/frankenphp:php8.4-bookworm

RUN install-php-extensions mysqli pdo_mysql mbstring curl gd zip

WORKDIR /app
COPY . .

ENTRYPOINT []
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/router.php"]
