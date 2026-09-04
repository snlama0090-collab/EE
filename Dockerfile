# ============================================================
# WattPulse — Production Dockerfile for PandaStack
# Multi-stage build: composer dependencies → PHP + Apache runtime
# ============================================================

# ---- Stage 1: Composer (install production dependencies) ----
FROM composer:2 AS composer_stage
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --ignore-platform-reqs

# ---- Stage 2: PHP + Apache runtime ----
FROM php:8.2-apache

# Install required PHP extensions: pdo_mysql (database), gd (image resize), mbstring (PHPMailer)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql mbstring \
    && apt-get purge -y --auto-remove libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (required for .htaccess rules)
RUN a2enmod rewrite

# Set Apache DocumentRoot to the public/ directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides in the document root
RUN { \
        echo '<Directory /var/www/html/public>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/allow-override.conf \
    && a2enconf allow-override

# Alias /api/ to the project-root api/ directory (API lives outside public/)
RUN { \
        echo 'Alias /api /var/www/html/api'; \
        echo '<Directory /var/www/html/api>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/api-alias.conf \
    && a2enconf api-alias

# Copy composer dependencies from stage 1
COPY --from=composer_stage /app/vendor /var/www/html/vendor

# Copy application code
COPY . /var/www/html/

# Ensure proper ownership for Apache
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 (Apache default)
EXPOSE 80

# Apache runs in foreground by default in this image
CMD ["apache2-foreground"]
