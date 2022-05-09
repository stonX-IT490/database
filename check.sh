#!/bin/bash
MACHINE=10.4.90.51
while :
do
  exec 3>/dev/tcp/${MACHINE}/22
  if [ $? -eq 0 ]; then
    systemctl stop rmq-websrv
    systemctl stop rmq-dmz
    systemctl stop rmq-push
  else
    systemctl start rmq-websrv
    systemctl start rmq-dmz
    systemctl start rmq-push
  fi
done
