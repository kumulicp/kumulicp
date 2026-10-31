#!/bin/bash

./vendor/bin/sail up -d

while true; do
    ./vendor/bin/sail artisan queue:listen
    sleep 1
done &

while true; do
    ./vendor/bin/sail artisan queue:listen database-long --queue=long --sleep=3 --tries=1 --timeout=900
    sleep 1
done &

./scripts/dev-cron.sh
