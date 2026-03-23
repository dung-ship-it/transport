FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip

WORKDIR /app
COPY . .

RUN mkdir -p uploads/fuel_receipts && chmod -R 777 uploads

CMD php -S 0.0.0.0:${PORT:-8080} -t .