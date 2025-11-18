#!/bin/ash
### Install Scheduler Dependencies

apk add nginx certbot certbot-nginx php84-fpm php84-fpm php84-mysqli php84-mbstring php84-json php84-session php84-pdo php84-pdo_mysql tailscale mariadb mariadb-client

rc-update add tailscale default
rc-update add nginx default
rc-update add php-fpm84 default
rc-update add mariadb default

cp nginx_default.conf /etc/nginx/http.d/default.conf

mkdir -p /etc/nginx/snippets/
cp fastcgi-php.conf /etc/nginx/snippets/fastcgi-php.conf

certbot certonly -d <domain> -m <email>

/etc/init.d/nginx start
/etc/init.d/php-fpm84 start

/etc/init.d/mariadb setup
/etc/init.d/mariadb start

mkdir /usr/share/webapps/phpmyadmin/tmp/
chown -R nginx:nginx /usr/share/webapps/phpmyadmin
find /usr/share/webapps/phpmyadmin -type d -exec chmod 755 {} \;
find /usr/share/webapps/phpmyadmin -type f -exec chmod 644 {} \;

mkdir /uploads/
chown -R nginx:nginx /uploads
chmod -R 777 /uploads

mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p mysql
