#!/usr/bin/env bash
# =============================================================================
# build.sh — Script build cho Render.com (Free tier)
# =============================================================================
# Render thực thi file này một lần duy nhất khi deploy.
# Các lệnh sau đây:
#   1. Kiểm tra và cài extension PHP cần thiết
#   2. Cài Composer dependencies (production-only)
#   3. Tối ưu Laravel cho production
#
# Lưu ý: Render Free dùng Ubuntu 22.04 với PHP 8.2+ sẵn có.
#         ext-zip và ext-xml thường đã có, nhưng ta đảm bảo chắc chắn.
# =============================================================================

set -e  # Dừng ngay nếu có lỗi

echo "======================================================"
echo "  Quiz Shuffler – Render Build Script"
echo "======================================================"

# ── 0. Kiểm tra phiên bản PHP ──────────────────────────────────────────────
echo ""
echo "→ PHP version:"
php --version

# ── 1. Cài đặt extension PHP (nếu chưa có) ─────────────────────────────────
# Render cho phép cài package apt trong build script
echo ""
echo "→ Installing required PHP extensions..."

# PHPWord cần: zip, xml, gd (cho hình ảnh nếu có)
# ext-mbstring thường có sẵn nhưng ta chắc chắn
sudo apt-get update -qq
sudo apt-get install -y -qq \
    php8.2-zip \
    php8.2-xml \
    php8.2-mbstring \
    php8.2-gd \
    php8.2-curl

echo "✓ PHP extensions installed"

# ── 2. Cài Composer dependencies ───────────────────────────────────────────
echo ""
echo "→ Installing Composer dependencies (production)..."
composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-suggest

echo "✓ Composer install done"

# ── 3. Thiết lập file .env ──────────────────────────────────────────────────
echo ""
echo "→ Setting up .env..."
if [ ! -f ".env" ]; then
    cp .env.render .env
    echo "✓ .env created from .env.render"
fi

# ── 4. Generate APP_KEY (nếu chưa có) ──────────────────────────────────────
if grep -q "APP_KEY=$" .env || grep -q "APP_KEY=base64:$" .env; then
    echo "→ Generating APP_KEY..."
    php artisan key:generate --force
    echo "✓ APP_KEY generated"
fi

# ── 5. Cache Laravel config/routes để tăng tốc ─────────────────────────────
echo ""
echo "→ Optimizing Laravel for production..."
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo ""
echo "======================================================"
echo "  ✅ Build completed successfully!"
echo "======================================================"