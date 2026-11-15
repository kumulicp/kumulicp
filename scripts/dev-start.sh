#!/bin/bash

# Stop the background queue loops and the cron loop when this script exits
# (Ctrl+C, terminal closed, or sail failing). Without this the `&` loops below
# outlive the script and pile up on every run.
trap 'trap - EXIT INT TERM; kill 0' EXIT INT TERM

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
