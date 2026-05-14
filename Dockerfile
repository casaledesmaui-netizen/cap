FROM dunglas/frankenphp:php8.4-bookworm

RUN install-php-extensions mysqli pdo_mysql mbstring curl gd zip

RUN mkdir -p /tmp/php_sessions && chmod 1777 /tmp/php_sessions && \
    echo "session.save_path = /tmp/php_sessions" >> /usr/local/etc/php/php.ini

WORKDIR /app
COPY . .

ENTRYPOINT []
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/router.php"]
