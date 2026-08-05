FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    curl \
    libzip-dev \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create www-data user with proper permissions
RUN chown -R www-data:www-data /var/www/html

# Copy application files
COPY --chown=www-data:www-data . /var/www/html

# Install dependencies (if composer.json exists)
RUN if [ -f composer.json ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction; \
    fi

# Configure PHP-FPM to run as www-data
RUN sed -i 's/user = nobody/user = www-data/g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/group = www-data/g' /usr/local/etc/php-fpm.d/www.conf

# Outside /var/www/html on purpose: docker-compose.yml bind-mounts the
# repository over that path, which would hide anything placed there at build
# time - including this script.
COPY docker/docker-entrypoint-php.sh /usr/local/bin/docker-entrypoint-php.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-php.sh

# Expose PHP-FPM port
EXPOSE 9000

ENTRYPOINT ["docker-entrypoint-php.sh"]
CMD ["php-fpm"]
