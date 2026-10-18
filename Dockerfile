# ==============================================================================
# SkyKin Automatic Dialer - Production Dockerfile
# Optimized PHP 8.2 with Apache, SQLite3, Zip, and OPcache
# ==============================================================================

FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies & libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libsqlite3-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install and configure PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pdo_sqlite \
    opcache \
    zip

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure PHP settings for production & large audio/lead uploads
RUN { \
    echo 'upload_max_filesize = 50M'; \
    echo 'post_max_size = 50M'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_time = 300'; \
    echo 'date.timezone = UTC'; \
    echo 'session.cookie_httponly = 1'; \
    echo 'session.use_strict_mode = 1'; \
    echo 'opcache.enable = 1'; \
    echo 'opcache.memory_consumption = 128'; \
    echo 'opcache.interned_strings_buffer = 8'; \
    echo 'opcache.max_accelerated_files = 4000'; \
    echo 'opcache.validate_timestamps = 1'; \
    echo 'opcache.revalidate_freq = 2'; \
} > /usr/local/etc/php/conf.d/custom-production.ini

# Copy application source code
COPY . /var/www/html/

# Create necessary runtime directories and set ownership
RUN mkdir -p /var/www/html/data /var/www/html/assets/audio \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data /var/www/html/assets/audio

# Setup entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose HTTP port
EXPOSE 80

# Healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/login.php || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
