# kumulicp (the laravel.test container) as seen from inside the cluster. Pods
# can't resolve the compose service name, so this is a Service with a fixed
# endpoint; setup.sh refreshes the IP whenever it runs.
apiVersion: v1
kind: Service
metadata:
  name: laravel
  namespace: kmanager-test
spec:
  ports:
    - port: 80
---
apiVersion: v1
kind: Endpoints
metadata:
  name: laravel
  namespace: kmanager-test
subsets:
  - addresses:
      - ip: ${LARAVEL_IP}
    ports:
      - port: 80
