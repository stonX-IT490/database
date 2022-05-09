#!/bin/bash

primary="10.4.90.52"
secondary="10.4.90.62"

broker=$primary
last_setting=$primary

while true; do

    if ping -c 1 -W 1 $primary; then
    broker=$primary
    else
    broker=$secondary
    fi
    echo $broker

    sleep 11s
    
    if [ "$broker" != "$last_setting" ]; then
    echo "restarting services..."
    systemctl restart rmq-websrv
    systemctl restart rmq-dmz
    systemctl restart rmq-push
    last_setting=$broker
    fi
done
