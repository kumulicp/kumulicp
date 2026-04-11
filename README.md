# KumuliCP

KumuliCP wants to simplify app hosting with the power and flexibility of Kubernetes and Helm Charts.

::: error
This is currently in the Alpha stage of development and should not be used in production. Breaking changes are all I can guarantee right now. Please provide feedback, bug reports, feature requests or even code so this can be the best control panel out there.

:::

## ✨ Features

### Admin

- Built on Kubernetes: Integrated with Rancher to ease Kubernetes management
- App management: Version management so you can upgrade all your clients apps automatically
- Plans: Create plans to provide flexible pricing, feature options and app configurations
- Billing: Integrate with Stripe for recurring monthly billing
- Domain registration: Integration with Namecheap built-in
- LDAP: Built-in integration with LDAP for user and groups management
- SSO: Add multiple SSO providers
- Backups/Restore: Basic scheduled backup and restore available for MySQL databases
- Shared apps: Allow different clients to login to the same app by making it shared.

### End-users

- Multi-tenancy: Self-registration
- Apps: Choose only the apps you want activated
- Users: Manage users
- Centralized Permissions: With LDAP enabled, apps can be connected to LDAP so clients can control app permissions from one place!
- Domain: Register or transfer domain names and manage your DNS records
- Groups: Manage users in groups. (Integrated with Nextcloud to add Team folders, which requires LDAP)

## Getting Started

This will get a basic setup to start a dev environment, but won’t be able to do much of anything. In order to test the full potential of KumuliCP, you need a Kubernetes w/Rancher server. A helm chart is in the works!

### Requirements

- Docker

### Setup

Copy the .env.dev and docker-compose.yaml.sample files to .env and docker-compose.yml respectively.

The default values should start a working dev environment

#### Quick Setup

1. Run the scripts/dev-setup.sh script to get the KumuliCP dev environment up and running in no time.
2. Then run scripts/dev-start.sh to start sail, artisan queue and run scheduled jobs.

#### Manual Setup

The dev environment relies on Sail being installed through composer, but installing composer is a few extra steps. To save you those steps, run this docker container instead:

```
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v $(pwd):/var/www/html \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

Then run each of these commands to get start:

```
# Build the containers
sail up

# Generate a encryption key (only do this once!)
sail artisan key:generate

# Setup the database tables
sail artisan migrate

# Build the frontend
sail npm install
sail npm run build
```

If you want some demo data (which is minimal right now), run this:

```
sail artisan db:seed --class=DemoSeeder
```

If you want to build the frontend run:

```
sail npm run watch
```

Assuming no errors, go to http://localhost in your browser

To view the database with phpmyadmin go to http://localhost:8080

### Rancher

::: info
This will not create a fully testable rancher environment, but you can activate apps and see that they’ve loaded in Rancher successfully.

:::

1. Copy the rancher service and volume from docker-compose.yml.extras to your docker-compose.yml
2. Run sail up. Give it time to setup
3. Open it in your browser. It will be https://localhost (don’t forget the ‘s’)
4. Login using the the bootstrap password set in the docker-compose env variable CATTLE_BOOTSTRAP_PASSWORD
5. Create an API key
6. Get the Default project ID or create a new project and copy that ID from the YAML file.
7. In KumuliCP, go to System Admin > Server Settings > Servers and a new server and make sure the Interface is set to rancher.
8. Copy the API key and secret to the added Rancher server. Make the address and host https://k-rancher-1 or change k-rancher-1 to whatever the name of the rancher docker container is. Add a setting under Settings called project_id and copy the project ID from step 6.

## Documentation

For more information about Sail, go to https://laravel.com/docs/13.x/sail

For more information on the Rancher docker, go to: https://ranchermanager.docs.rancher.com/getting-started/installation-and-upgrade/other-installation-methods/rancher-on-a-single-node-with-docker

Documentation for KumuliCP will be released soon!
