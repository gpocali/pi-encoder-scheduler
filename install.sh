#!/bin/ash
### Install/Update Scheduler Dependencies
### Safe to run on existing systems - uses idempotent operations

set -e  # Exit on error

echo "=== pi-encoder-scheduler Installation/Update ==="

### 1. Package Installation (idempotent) ###
echo " Installing packages..."
apk add --no-cache \
    nginx \
    certbot \
    certbot-nginx \
    php84-fpm \
    php84-pdo_mysql \
    php84-mbstring \
    php84-json \
    php84-session \
    php84-pdo \
    tailscale \
    mariadb \
    mariadb-client \
    rrdtool \
    php84-gd \
    php84-cli

### 2. Service Registration (idempotent) ###
echo " Registering services..."
rc-update add tailscale default    2>/dev/null || true
rc-update add nginx default        2>/dev/null || true
rc-update add php-fpm84 default    2>/dev/null || true
rc-update add mariadb default      2>/dev/null || true

### 3. Nginx Configuration ###
echo " Configuring nginx..."

# Create snippets directory
mkdir -p /etc/nginx/snippets/

# Backup existing config if it exists
if [ -f /etc/nginx/http.d/default.conf ]; then
    echo " Backing up existing nginx config..."
    cp /etc/nginx/http.d/default.conf /etc/nginx/http.d/default.conf.bak.$(date +%Y%m%d%H%M%S)
fi

# Copy new config
SCRIPT_DIR=$(dirname "$0")
cp "$SCRIPT_DIR/nginx_default.conf" /etc/nginx/http.d/default.conf

# Copy fastcgi-php.conf from repo
if [ -f "$SCRIPT_DIR/fastcgi-php.conf" ]; then
    echo " Installing fastcgi-php.conf..."
    cp "$SCRIPT_DIR/fastcgi-php.conf" /etc/nginx/snippets/fastcgi-php.conf
elif [ -f /etc/nginx/snippets/fastcgi-php.conf ]; then
    echo " fastcgi-php.conf already exists, skipping..."
else
    echo " WARNING: fastcgi-php.conf not found, creating basic version..."
    cat > /etc/nginx/snippets/fastcgi-php.conf << 'FASTCGI'
fastcgi_param  SCRIPT_FILENAME    $document_root$fastcgi_script_name;
fastcgi_param  QUERY_STRING       $query_string;
fastcgi_param  REQUEST_METHOD     $request_method;
fastcgi_param  CONTENT_TYPE       $content_type;
fastcgi_param  CONTENT_LENGTH     $content_length;

fastcgi_param  SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param  REQUEST_URI        $request_uri;
fastcgi_param  DOCUMENT_URI       $document_uri;
fastcgi_param  DOCUMENT_ROOT      $document_root;
fastcgi_param  SERVER_PROTOCOL    $server_protocol;
fastcgi_param  REQUEST_SCHEME     $scheme;
fastcgi_param  HTTPS              $https if_not_empty;

fastcgi_param  GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param  SERVER_SOFTWARE    nginx/$nginx_version;

fastcgi_param  REMOTE_ADDR        $remote_addr;
fastcgi_param  REMOTE_PORT        $remote_port;
fastcgi_param  SERVER_ADDR        $server_addr;
fastcgi_param  SERVER_PORT        $server_port;
fastcgi_param  SERVER_NAME        $server_name;

# PHP only, required if PHP was built with --enable-force-cgi-redirect
fastcgi_param  REDIRECT_STATUS    200;
FASTCGI
        echo " Created fallback fastcgi-php.conf"
    fi
fi

### 4. SSL Certificate ###
echo " Checking SSL certificate..."
# Get domain from config or use default
DOMAIN=$(grep -oP 'server_name\s+\K[^;]+' /etc/nginx/http.d/default.conf 2>/dev/null | head -1)
if [ -z "$DOMAIN" ]; then
    DOMAIN="scheduler.example.com"
fi

# Check if certificate already exists
if certbot certificates 2>/dev/null | grep -q "$DOMAIN"; then
    echo " Certificate for $DOMAIN already exists, skipping certbot..."
else
    echo " Requesting certificate for $DOMAIN..."
    echo " NOTE: You will need to update the domain name in nginx_default.conf"
    certbot certonly --nginx -d "$DOMAIN" -m admin@example.com --non-interactive --agree-tos || \
        echo " WARNING: Certificate request failed. Please run certbot manually."
fi

### 5. Start Services ###
echo " Starting services..."
/etc/init.d/nginx start       2>/dev/null || true
/etc/init.d/php-fpm84 start  2>/dev/null || true

### 6. Database Setup (only if not already initialized) ###
echo " Checking database..."
if [ ! -d /var/lib/mysql/mysql ] || [ ! -f /etc/mysql/my.cnf ]; then
    echo " Initializing MariaDB..."
    /etc/init.d/mariadb setup
else
    echo " MariaDB already initialized, skipping setup..."
fi

/etc/init.d/mariadb start 2>/dev/null || true

### 7. phpMyAdmin Setup (if installed) ###
if [ -d /usr/share/webapps/phpmyadmin ]; then
    echo " Configuring phpMyAdmin..."
    mkdir -p /usr/share/webapps/phpmyadmin/tmp/
    chown -R nginx:nginx /usr/share/webapps/phpmyadmin
    find /usr/share/webapps/phpmyadmin -type d -exec chmod 755 {} \;
    find /usr/share/webapps/phpmyadmin -type f -exec chmod 644 {} \;
fi

### 8. Upload Directory ###
echo " Setting up upload directory..."
mkdir -p /uploads/
chown -R nginx:nginx /uploads 2>/dev/null || true
chmod -R 777 /uploads

### 9. Database Timezone Data ###
echo " Loading timezone data..."
mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb -u root -p mysql 2>/dev/null || \
    echo " Warning: Could not load timezone data (may already be loaded)"

### 10. Final Status ###
echo ""
echo "=== Installation/Update Complete ==="
echo ""
echo " Services configured:"
echo "   - Nginx: /etc/nginx/http.d/default.conf"
echo "   - PHP-FPM: php84-fpm"
echo "   - MariaDB: /var/lib/mysql"
echo "   - Certbot: ${DOMAIN}"
echo ""
echo " Next steps:"
echo "   1. Update db_info.conf with database credentials"
echo "   2. Run: mysql -u root -p < initialize.sql"
echo "   3. Run: php root/create_admin.php"
echo "   4. Configure Tailscale: tailscale up --accept-routes"
echo ""
