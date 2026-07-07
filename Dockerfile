FROM php:8.2-apache

# Instala MySQL server + extensões PHP
RUN apt-get update && apt-get install -y \
    default-mysql-server \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Configura Apache
RUN echo '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>' \
    >> /etc/apache2/apache2.conf

# Copia arquivos do site
COPY . /var/www/html/

# Script de inicialização
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["/docker-entrypoint.sh"]
