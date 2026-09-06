#!/bin/bash

./vendor/bin/sail up -d

./vendor/bin/sail artisan queue:listen &

./vendor/bin/sail artisan queue:listen database-long --queue=long --sleep=3 --tries=1 --timeout=900 &

./scripts/dev-cron.sh
