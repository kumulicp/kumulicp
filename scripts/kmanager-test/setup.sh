#!/usr/bin/env bash
#
# Two MySQL database servers, each with its own kmanager, in the local k3s
# cluster (namespace kmanager-test), sharing one local restic repository. For
# trying out database restores and migrations between servers.
#
# Needs the kmanager image: docker build -t kmanager:dev <dockers/kmanager>
#
# Safe to run again. Credentials are generated once into .generated.env.
set -euo pipefail

cd "$(dirname "$0")"
ROOT=$(cd ../.. && pwd)
export KUBECONFIG="$ROOT/kubeconfig.yaml"

if [ ! -f .generated.env ]; then
    {
        echo "MYSQL_ROOT_PASSWORD_1=$(openssl rand -hex 16)"
        echo "MYSQL_ROOT_PASSWORD_2=$(openssl rand -hex 16)"
        echo "MANAGER_PASSWORD=$(openssl rand -hex 16)"
        # One repository, so both kmanagers must use the same password
        echo "RESTIC_PASSWORD=$(openssl rand -hex 16)"
    } > .generated.env
    chmod 600 .generated.env
fi
# shellcheck disable=SC1091
. ./.generated.env

# kmanager reports back to kumulicp with an account's API token
API_TOKEN=$(cd "$ROOT" && ./vendor/bin/sail artisan tinker --execute 'echo App\Organization::find(1)->api_token;' | tail -n 1 | tr -d '\r\n')
[ "${#API_TOKEN}" -eq 60 ] || { echo "Could not read the API token of organization 1" >&2; exit 1; }
export API_TOKEN MANAGER_PASSWORD RESTIC_PASSWORD

# k3s runs its own containerd inside the k3s container
docker save kmanager:dev | docker exec -i k-k3s-1 ctr images import - > /dev/null

kubectl apply -f restic.yaml

LARAVEL_IP=$(docker inspect kumulicp-laravel.test-1 --format '{{(index .NetworkSettings.Networks "kumulicp_sail").IPAddress}}')
export LARAVEL_IP
envsubst '${LARAVEL_IP}' < laravel.yaml.tpl | kubectl apply -f -

for N in 1 2; do
    export N
    eval "export MYSQL_ROOT_PASSWORD=\$MYSQL_ROOT_PASSWORD_$N"
    envsubst '${N} ${MYSQL_ROOT_PASSWORD} ${MANAGER_PASSWORD} ${RESTIC_PASSWORD} ${API_TOKEN}' < instance.yaml.tpl | kubectl apply -f -
done

kubectl -n kmanager-test wait --for=condition=complete job/restic-init --timeout=180s
for N in 1 2; do
    kubectl -n kmanager-test rollout status statefulset/mysql-$N --timeout=300s
    kubectl -n kmanager-test rollout status deployment/kmanager-$N --timeout=300s
done

echo "kmanager-1: http://k3s:30081  (user kmanager, MySQL mysql-1.kmanager-test.svc.cluster.local)"
echo "kmanager-2: http://k3s:30082  (user kmanager, MySQL mysql-2.kmanager-test.svc.cluster.local)"
