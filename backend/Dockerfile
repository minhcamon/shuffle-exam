# Dùng bản PHP 8.2 có sẵn Apache
FROM php:8.2-apache

# Cài đặt các thư viện hệ điều hành cần thiết cho Laravel và ZipArchive
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip pdo_mysql

# Bật tính năng định tuyến (rewrite) của Apache
RUN a2enmod rewrite

# Chỉ định thư mục gốc là /public của Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# ==========================================
# VÁ LỖI TẠI ĐÂY: Di chuyển vào đúng thư mục
# ==========================================
WORKDIR /var/www/html

# Copy toàn bộ code vào trong server ảo
COPY . /var/www/html

# Cho phép Composer chạy dưới quyền quản trị (Root) trong Docker
ENV COMPOSER_ALLOW_SUPERUSER=1

# Cài đặt Composer và chạy lệnh install
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# CẤP QUYỀN ĐỌC/GHI CHO THƯ MỤC STORAGE (Cực kỳ quan trọng để lưu file đề)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache