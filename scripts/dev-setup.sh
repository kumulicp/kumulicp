#!/bin/bash

docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v $(pwd):/var/www/html:z \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

echo "alias sail='[ -f sail ] && bash sail || bash vendor/bin/sail'" >> ~/.bashrc
source ~/.bashrc

sail() {
    $(pwd)/vendor/bin/sail $@
}

sail up -d

echo "Waiting 60 seconds for containers to load..."

sleep 60

sail artisan key:generate
sail artisan migrate

sail npm install

sail npm run build

# sail artisan db:seed --class=DemoSeeder

sail down

echo "Development environment setup almost done..."
echo "Visit http://localhost to complete setup"
echo "Run 'sail artisan db:seed --class=DemoSeeder' to add basic content for development."
echo ""
echo "From now on, use the command 'sail up' to start the development environment"
echo "And run 'sail down' to stop it when you're finished"
echo ""
echo "Run 'sail npm watch' to edit javascript and vue.js in realtime"

