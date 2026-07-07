#!/bin/bash
set -e

# Inicia MySQL
service mariadb start

# Aguarda MySQL ficar pronto
sleep 3

# Cria banco e importa SQL
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS padasorte CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'padasorte'@'localhost' IDENTIFIED BY 'padasorte123';
GRANT ALL PRIVILEGES ON padasorte.* TO 'padasorte'@'localhost';
FLUSH PRIVILEGES;
EOF

# Importa o SQL se existir
if [ -f /var/www/html/SQL\ DO\ SISTEMA\ NOVO.sql ]; then
    mysql -u root padasorte < "/var/www/html/SQL DO SISTEMA NOVO.sql"
    echo "SQL importado com sucesso"
fi

# Inicia Apache
apache2-foreground
