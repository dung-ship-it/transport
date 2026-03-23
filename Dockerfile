FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip

WORKDIR /app
COPY . .

RUN mkdir -p uploads/fuel_receipts && chmod -R 777 uploads

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "."]
