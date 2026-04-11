#!/bin/bash

./vendor/bin/sail up -d

./vendor/bin/sail artisan queue:listen &

./scripts/dev-cron.sh
