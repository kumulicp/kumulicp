#/bin/bash

while true
do 
    ./vendor/bin/sail artisan schedule:run
    sleep 60
done
