FROM php:8.2-cli

# Install mysqli and required extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql mbstring

WORKDIR /app
COPY . /app/

# Create logs directory
RUN mkdir -p /app/logs && chmod 777 /app/logs

EXPOSE 8080

CMD php -S 0.0.0.0:${PORT:-8080} -t /app
