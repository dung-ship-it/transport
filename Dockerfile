FROM php:8.3-cli 
RUN apt-get update && apt-get install -y libpq-dev libcurl4-openssl-dev libgd-dev libzip-dev && docker-php-ext-install pdo pdo_pgsql pgsql mbstring curl gd zip 
WORKDIR /app 
COPY . . 
RUN mkdir -p uploads/fuel_receipts && chmod -R 777 uploads 
EXPOSE 8080 
CMD ["php", "-S", "0.0.0.0:8080", "-t", "."] 
