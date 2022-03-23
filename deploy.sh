#!/bin/bash

# Update repos
sudo apt update

# Do full upgrade of system
sudo apt full-upgrade -y

# Remove leftover packages and purge configs
sudo apt autoremove -y --purge

# Install required packages
sudo apt install -y ufw mariadb-server expect php-amqp php-bcmath php-cli php-common php-curl php-json php-mbstring php-mysql php-readline php-zip unzip wget inotify-tools

# Install Composer
sudo wget -O composer-setup.php https://getcomposer.org/installer
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
composer require php-amqplib/php-amqplib
composer update

# Setup firewall
sudo ufw --force enable
sudo ufw allow ssh
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Install zerotier
sudo apt install -y apt-transport-https ca-certificates curl gnupg lsb-release
curl -s https://install.zerotier.com | sudo bash

# Run commands from mysql_secure_installation
sudo expect -c "set timeout 10
spawn sudo mysql_secure_installation
expect \"Enter current password for root (enter for none):\"
send \"\r\"
expect \"Switch to unix_socket authentication\"
send \"n\r\"
expect \"Change the root password?\"
send \"n\r\"
expect \"Remove anonymous users?\"
send \"y\r\"
expect \"Disallow root login remotely?\"
send \"y\r\"
expect \"Remove test database and access to it?\"
send \"y\r\"
expect \"Reload privilege tables now?\"
send \"y\r\"
expect eof"

# Import DB Schema
sudo mysql < schema.sql
sudo mysql < stocks.sql

# Create user
sudo mysql -e "CREATE USER 'stonx'@'localhost' IDENTIFIED BY 'stonx_passwd';"
sudo mysql -e "GRANT ALL ON stonx.* TO 'stonx'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Setup rabbitmq listener
cd rabbit
git clone https://github.com/stonX-IT490/rabbitmq-common.git rabbitmq-webHost
git clone https://github.com/stonX-IT490/rabbitmq-common.git rabbitmq-dmzHost
git clone https://github.com/stonX-IT490/rabbitmq-common.git rabbitmq-pushHost
cp ../config.webHost.php rabbitmq-webHost/config.php
cp ../config.dmzHost.php rabbitmq-dmzHost/config.php
cp ../config.pushHost.php rabbitmq-pushost/config.php
cd ..

pwd=`pwd`'/rabbit'
serviceWebHost="[Unit]
Description=Webserver RabbitMQ Consumer Listener

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php -f $pwd/webserver.php

[Install]
WantedBy=multi-user.target"

serviceDmzHost="[Unit]
Description=DMZ RabbitMQ Consumer Listener

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php -f $pwd/dmz.php

[Install]
WantedBy=multi-user.target"

servicePushHost="[Unit]
Description=Push Notification RabbitMQ Consumer Listener

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php -f $pwd/push.php

[Install]
WantedBy=multi-user.target"

echo "$serviceWebHost" > rmq-websrv.service
echo "$serviceDmzHost" > rmq-dmz.service
echo "$servicePushHost" > rmq-push.service

sudo cp rmq-websrv.service /etc/systemd/system/
sudo cp rmq-dmz.service /etc/systemd/system/
sudo cp rmq-push.service /etc/systemd/system/
sudo systemctl start rmq-websrv
sudo systemctl start rmq-dmz
sudo systemctl start rmq-push
sudo systemctl enable rmq-websrv
sudo systemctl enable rmq-dmz
sudo systemctl enable rmq-push

# Setup Central Logging
git clone https://github.com/stonX-IT490/logging.git ~/logging
cd ~/logging
chmod +x deploy.sh
./deploy.sh
cd ~/
