FROM php:8.2-fpm

# 扩展：pdo_mysql / redis / gd / mbstring / zip / bcmath / pcntl / posix / sockets
# pcntl+posix 为 Workerman 必需；redis 走 pecl
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev libssl-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring gd zip bcmath pcntl posix sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# 生产 PHP 参数
RUN { \
      echo 'date.timezone=Asia/Shanghai'; \
      echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=200M'; \
      echo 'post_max_size=210M'; \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

# 完整项目（vendor 已随包送达；.env 由 compose 挂载，不打进镜像）
COPY . /app

RUN mkdir -p /app/runtime /app/public/storage \
    && chown -R www-data:www-data /app/runtime /app/public/storage

COPY deploy/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 9000 2346
CMD ["/usr/local/bin/start.sh"]
