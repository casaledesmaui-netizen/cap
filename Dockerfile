FROM dunglas/frankenphp:php8.4-bookworm

RUN install-php-extensions mysqli pdo_mysql mbstring curl gd zip

RUN mkdir -p /tmp/php_sessions && chmod 1777 /tmp/php_sessions && \
    echo "session.save_path = /tmp/php_sessions" > /usr/local/etc/php/conf.d/sessions.ini

WORKDIR /app
COPY . .

RUN mkdir -p assets/css/fonts assets/js && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" -o assets/css/bootstrap.min.css && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" -o assets/js/bootstrap.bundle.min.js && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" -o assets/css/bootstrap-icons.css && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/fonts/bootstrap-icons.woff2" -o assets/css/fonts/bootstrap-icons.woff2 && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/fonts/bootstrap-icons.woff" -o assets/css/fonts/bootstrap-icons.woff

ENTRYPOINT []
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/router.php"]
