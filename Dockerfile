FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    curl \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip

# Cài Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY . .

# Cài PhpSpreadsheet và các dependencies
RUN composer install --no-dev --optimize-autoloader

RUN mkdir -p uploads/fuel_receipts && chmod -R 777 uploads
RUN chmod +x /app/start.sh

ENTRYPOINT ["/app/start.sh"]