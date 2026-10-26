# One database server (MySQL + the kmanager that manages it). Rendered once per
# instance by setup.sh with envsubst: N is 1 or 2.
apiVersion: v1
kind: Secret
metadata:
  name: kmanager-${N}
  namespace: kmanager-test
stringData:
  MYSQL_ROOT_PASSWORD: "${MYSQL_ROOT_PASSWORD}"
  ROOT_SQL_PASSWORD: "${MYSQL_ROOT_PASSWORD}"
  MANAGER_PASSWORD: "${MANAGER_PASSWORD}"
  RESTIC_PASSWORD: "${RESTIC_PASSWORD}"
  API_TOKEN: "${API_TOKEN}"
---
apiVersion: v1
kind: Service
metadata:
  name: mysql-${N}
  namespace: kmanager-test
spec:
  selector:
    app: mysql-${N}
  ports:
    - port: 3306
---
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: mysql-${N}
  namespace: kmanager-test
spec:
  serviceName: mysql-${N}
  replicas: 1
  selector:
    matchLabels:
      app: mysql-${N}
  template:
    metadata:
      labels:
        app: mysql-${N}
    spec:
      containers:
        - name: mysql
          image: mysql:8.4
          # mydumper/myloader are built against the MariaDB connector
          args: ["--mysql-native-password=ON"]
          env:
            - name: MYSQL_ROOT_PASSWORD
              valueFrom:
                secretKeyRef: {name: kmanager-${N}, key: MYSQL_ROOT_PASSWORD}
            - name: MYSQL_ROOT_HOST
              value: "%"
          ports:
            - containerPort: 3306
          # ping succeeds whenever the server is up, with or without a login
          readinessProbe:
            exec:
              command: ["mysqladmin", "ping", "-h", "127.0.0.1"]
            initialDelaySeconds: 10
            periodSeconds: 5
          volumeMounts:
            - name: data
              mountPath: /var/lib/mysql
  volumeClaimTemplates:
    - metadata:
        name: data
      spec:
        accessModes: ["ReadWriteOnce"]
        resources:
          requests:
            storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: kmanager-${N}
  namespace: kmanager-test
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: kmanager-${N}
  template:
    metadata:
      labels:
        app: kmanager-${N}
    spec:
      containers:
        # The API. Entrypoint (restic password file) comes from the image, so
        # only args are given.
        - name: kmanager
          image: kmanager:dev
          imagePullPolicy: Never
          args: ["gunicorn", "-b", "0.0.0.0:5000", "--timeout", "120", "app:app"]
          envFrom: &env
            - secretRef: {name: kmanager-${N}}
            - configMapRef: {name: kmanager-${N}}
          ports:
            - containerPort: 5000
          readinessProbe:
            httpGet: {path: /, port: 5000}
            initialDelaySeconds: 5
          volumeMounts: &mounts
            - {name: restic-repo, mountPath: /restic-repo}
        # Runs the queued backups and restores
        - name: worker
          image: kmanager:dev
          imagePullPolicy: Never
          args: ["supervisord", "-c", "/home/app/supervisord.conf"]
          envFrom: *env
          volumeMounts: *mounts
        # The queue for this kmanager only: a queue shared with the other one
        # would hand its jobs to the wrong database server
        - name: redis
          image: redis:7
        # kumulicp tells kmanager to call back to its APP_URL
        # (http://localhost:8000), which from in here is this container
        - name: callback-proxy
          image: alpine/socat
          args: ["TCP-LISTEN:8000,fork,reuseaddr", "TCP:laravel:80"]
      volumes:
        - name: restic-repo
          persistentVolumeClaim:
            claimName: restic-repo
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: kmanager-${N}
  namespace: kmanager-test
data:
  DB_TYPE: mysql
  ROOT_SQL_HOST: mysql-${N}
  ROOT_SQL_USER: root
  MANAGER_USER: kmanager
  REDIS_URL: localhost
  CC_URL: http://localhost:8000
  EMAIL_ERRORS: "False"
  SMTP_PASSWORD: unused
  BACKUP_REPO: /restic-repo
  # restic is pointed at a local repository, so these are never used
  AWS_ACCESS_KEY_ID: unused
  AWS_SECRET_ACCESS_KEY: unused
---
apiVersion: v1
kind: Service
metadata:
  name: kmanager-${N}
  namespace: kmanager-test
spec:
  type: NodePort
  selector:
    app: kmanager-${N}
  ports:
    - port: 5000
      nodePort: 3008${N}
