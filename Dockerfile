# Stage 1: Build Frontend
FROM node:18 as builder

# Build arguments for environment configuration
ARG WS_SERVER=wss://ws.piece.one
ARG API_SERVER=https://api.piece.one
ENV WS_SERVER=${WS_SERVER}
ENV API_SERVER=${API_SERVER}

WORKDIR /app/front-end

# Copy frontend source
COPY front-end/package.json ./
RUN npm install

COPY front-end/ .
# Create the target directory structure so gulp can write to ../Applications/web
RUN mkdir -p /app/Applications/web
RUN npm run build
# Debug: list files to verify build
RUN ls -la /app/Applications/web

# Stage 2: Final Image
FROM php:8.2-cli

# Install extension installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install dependencies and extensions (Pre-compiled)
RUN install-php-extensions \
    opcache \
    pcntl \
    posix \
    sockets \
    zip \
    gmp \
    event \
    mongodb \
    redis

# Enable JIT
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.jit_buffer_size=100M" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# Copy backend files (this includes Applications, but we will overwrite/merge web)
COPY . .

# Copy built frontend assets from builder stage into Applications/web
COPY --from=builder /app/Applications/web /app/Applications/web

# Start command
CMD ["php", "start.php", "start"]
