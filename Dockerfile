FROM php:8.1.31-alpine3.20

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    mbstring \
    pcntl

# Install Composer (pinned version)
COPY --from=composer:2.9.5 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json ./

RUN composer install \
    --no-scripts \
    --no-progress \
    --prefer-dist \
    --no-interaction

# Copy source files
COPY . .

CMD ["php", "-v"]

