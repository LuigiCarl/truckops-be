# ==============================================================================
# Stage 1: Vendor Builder (Composer)
# ==============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# ==============================================================================
# Stage 2: Production Application Runtime (PHP 8.4 FPM + Nginx + Supervisord)
# ==============================================================================
FROM php:8.4-fpm-alpine

# Set system metadata
LABEL maintainer="TruckOps Logistics"
LABEL description="Production container for TruckOps Laravel 12 Backend on Render"

# Install production OS dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    gettext \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    postgresql-dev \
    postgresql-client \
    mysql-client

# Configure and compile PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        bcmath \
        opcache \
        zip \
        gd \
        intl \
        mbstring

# Configure production PHP runtime
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/memory_limit = .*/memory_limit = 256M/' "$PHP_INI_DIR/php.ini" \
    && sed -i 's/upload_max_filesize = .*/upload_max_filesize = 25M/' "$PHP_INI_DIR/php.ini" \
    && sed -i 's/post_max_size = .*/post_max_size = 25M/' "$PHP_INI_DIR/php.ini" \
    && sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$PHP_INI_DIR/php.ini"

# Set application working directory
WORKDIR /var/www/html

# Copy vendor dependencies from builder stage
COPY --from=vendor /app/vendor /var/www/html/vendor

# Copy application source code
COPY . /var/www/html

# Copy Docker service configurations
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Fix line endings, permissions, and runtime directory structure
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && rm -rf /etc/nginx/http.d/* /etc/nginx/conf.d/* \
    && mkdir -p /var/log/supervisor /var/log/nginx /run/nginx /etc/supervisor/conf.d \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/log/nginx /run/nginx \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Dynamic port binding (Render default: 10000)
EXPOSE 10000

# Execute entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
