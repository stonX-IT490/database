#!/bin/bash

read -p "Which cluster? (prod, qa, dev) " cluster

rabbit_ip="broker"
check=$( getent hosts | grep -e broker )

if [ "$check" == "" ]; then
  if [ $cluster == "dev" ]; then
    echo "10.4.90.102 broker" | sudo tee -a /etc/hosts
  fi

  if [ $cluster == "qa" ]; then
    echo "10.4.90.152 broker" | sudo tee -a /etc/hosts
  fi

  if [ $cluster == "prod" ]; then
    echo "10.4.90.52 broker" | sudo tee -a /etc/hosts
    echo "10.4.90.62 broker" | sudo tee -a /etc/hosts
  fi
fi

# Update repos
sudo apt update

# Do full upgrade of system
sudo apt full-upgrade -y

# Remove leftover packages and purge configs
sudo apt autoremove -y --purge

# Install required packages
sudo apt install -y ufw mariadb-server expect php-amqp php-bcmath php-cli php-common php-curl php-json php-mbstring php-mysql php-readline php-zip unzip wget inotify-tools

# Setup firewall
sudo ufw --force enable
sudo ufw allow ssh
sudo ufw allow 3306
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
sudo mysql < currencies.sql

# Create user
sudo mysql -e "CREATE USER 'stonx'@'localhost' IDENTIFIED BY 'stonx_passwd';"
sudo mysql -e "GRANT ALL ON stonx.* TO 'stonx'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Setup rabbitmq listener
cd rabbit
git clone git@github.com:stonX-IT490/rabbitmq-common.git rabbitmq-webHost
cd rabbitmq-webHost
./deploy.sh
cd ..
git clone git@github.com:stonX-IT490/rabbitmq-common.git rabbitmq-dmzHost
cd rabbitmq-dmzHost
./deploy.sh
cd ..
git clone git@github.com:stonX-IT490/rabbitmq-common.git rabbitmq-pushHost
cd rabbitmq-pushHost
./deploy.sh
cd ..

rabbitWebHost="<?php

\$config = [
  'host' =>'$rabbit_ip',
  'port' => 5672,
  'username' => 'db',
  'password' => 'stonx_mariadb',
  'vhost' => 'webHost'
];

?>"

rabbitDmzHost="<?php

\$config = [
  'host' => '$rabbit_ip',
  'port' => 5672,
  'username' => 'db',
  'password' => 'stonx_mariadb',
  'vhost' => 'dmzHost'
];

?>"

rabbitPushHost="<?php

\$config = [
  'host' => '$rabbit_ip',
  'port' => 5672,
  'username' => 'db',
  'password' => 'stonx_mariadb',
  'vhost' => 'pushHost'
];

?>"

echo "$rabbitWebHost" > rabbitmq-webHost/config.php
echo "$rabbitDmzHost" > rabbitmq-dmzHost/config.php
echo "$rabbitPushHost" > rabbitmq-pushHost/config.php
cd ..

pwd=`pwd`

serviceWebHost="[Unit]
Description=Webserver RabbitMQ Consumer Listener

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php -f $pwd/rabbit/webserver.php

[Install]
WantedBy=multi-user.target"

serviceDmzHost="[Unit]
Description=DMZ RabbitMQ Consumer Listener

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php -f $pwd/rabbit/dmz.php

[Install]
WantedBy=multi-user.target"

servicePushHost="[Unit]
Description=Push Notification RabbitMQ Consumer Listener

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php -f $pwd/rabbit/push.php

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

serviceCheckProd1="[Unit]
Description=Check if database-prod1 is up

[Service]
Type=simple
Restart=always
ExecStart=$pwd/check.sh

[Install]
WantedBy=multi-user.target"

if [ $cluster == "prod" ]; then
  read -p "Which host? (prod1, prod2) " vm_type
  if [ $vm_type == "prod2" ]; then
    echo "$serviceCheckProd1" > check-prod1.service
    sudo cp check-prod1.service /etc/systemd/system/
    sudo systemctl start check-prod1
    sudo systemctl enable check-prod1
  fi
fi

autoRestart="[Unit]
Description=RMQ autoRestart Service

[Service]
Type=simple
Restart=always
WorkingDirectory=$pwd
ExecStart=/usr/bin/bash -f $pwd/autoRestart.sh
User=root
Group=root

[Install]
WantedBy=multi-user.target"

# Create autoRestart service in systemd
if [ $cluster == "prod" ]; then
  chmod +x $pwd/autoRestart.sh

  echo "$autoRestart" > rmq-autoRestart.service

  sudo cp rmq-autoRestart.service /etc/systemd/system/
  sudo systemctl start rmq-autoRestart
  sudo systemctl enable rmq-autoRestart
fi

# Setup Central Logging
git clone git@github.com:stonX-IT490/logging.git ~/logging
cd ~/logging
chmod +x deploy.sh
./deploy.sh
cd ~/
