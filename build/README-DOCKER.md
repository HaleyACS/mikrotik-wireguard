# Docker / Docker Compose testing

This document explains how to build and run MikroTik WireGuard Peer Manager locally using Docker Compose.

The Compose setup is intended for local development and testing of the container image before deploying it with Kubernetes or Helm.

## Files

The Docker-related files live in the `build/` directory:

```text
build/
├── Dockerfile
├── compose.yaml
├── .env.example
└── README-DOCKER.md
```

The Compose file builds the application image from the repository root using `build/Dockerfile`.

## Prerequisites

You need:

- Docker
- Docker Compose v2 (`docker compose`)
- Network connectivity from the Docker host/container to the MikroTik RouterOS management address
- A RouterOS user with sufficient permissions for the selected API mode

For REST mode, the RouterOS user normally needs at least:

```text
read,write,rest-api
```

Additional permissions may be required depending on the operations performed by the application.

## Prepare the environment file

Copy the example environment file:

```bash
cp build/.env.example build/.env
```

Edit it:

```bash
vi build/.env
```

Example:

```dotenv
MIKROTIK_HOST=10.0.2.254
MIKROTIK_USERNAME=wgapi
MIKROTIK_PASSWORD='CHANGE_ME'

MIKROTIK_API_MODE=rest
MIKROTIK_SSL_VERIFY=false
MIKROTIK_INTERFACE=wireguard

WIREGUARD_SUBNET=10.0.16.0/24
WIREGUARD_SERVER_IP=10.0.16.1

CLIENT_ENDPOINT=terminus.example.org:13231
CLIENT_ALLOWED_IPS='10.0.16.0/24, 10.0.2.0/24'
CLIENT_DNS='10.0.2.254, 1.1.1.1'
```

Do not commit `build/.env`.

Add this entry to `.gitignore`:

```gitignore
build/.env
```

## Configuration variables

### RouterOS connection

`MIKROTIK_HOST`

RouterOS management IP address or hostname reachable from the container.

Example:

```dotenv
MIKROTIK_HOST=10.0.2.254
```

`MIKROTIK_USERNAME`

RouterOS API username.

`MIKROTIK_PASSWORD`

RouterOS API password.

`MIKROTIK_API_MODE`

API mode used by the application.

Supported values:

```text
rest
native
```

Example:

```dotenv
MIKROTIK_API_MODE=rest
```

`MIKROTIK_SSL_VERIFY`

Controls TLS certificate verification when REST mode uses HTTPS.

Example for a RouterOS installation using a self-signed certificate:

```dotenv
MIKROTIK_SSL_VERIFY=false
```

For production, using a trusted certificate and enabling verification is recommended.

`MIKROTIK_INTERFACE`

Name of the existing WireGuard interface on RouterOS.

Example:

```dotenv
MIKROTIK_INTERFACE=wireguard
```

## WireGuard network

`WIREGUARD_SUBNET`

WireGuard address space used for peer allocation.

Example:

```dotenv
WIREGUARD_SUBNET=10.0.16.0/24
```

`WIREGUARD_SERVER_IP`

RouterOS WireGuard interface address inside that subnet.

Example:

```dotenv
WIREGUARD_SERVER_IP=10.0.16.1
```

This is not necessarily the RouterOS LAN or management address.

## Generated client configuration

`CLIENT_ENDPOINT`

Public endpoint used by generated WireGuard client configurations.

Example:

```dotenv
CLIENT_ENDPOINT=terminus.example.org:13231
```

`CLIENT_ALLOWED_IPS`

Networks routed through the WireGuard tunnel by generated clients.

Example:

```dotenv
CLIENT_ALLOWED_IPS='10.0.16.0/24, 10.0.2.0/24'
```

For a full-tunnel IPv4 configuration:

```dotenv
CLIENT_ALLOWED_IPS='0.0.0.0/0'
```

`CLIENT_DNS`

One or more DNS servers added to generated WireGuard client configurations.

Use a comma-separated list:

```dotenv
CLIENT_DNS='10.0.2.254, 1.1.1.1'
```

The generated client configuration will contain:

```ini
DNS = 10.0.2.254, 1.1.1.1
```

## Build and start

Run these commands from the repository root:

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  up --build -d
```

Docker Compose will:

1. build the application image using `build/Dockerfile`;
2. generate the runtime RouterOS configuration from the environment variables;
3. start the application;
4. expose the web interface on port `8080`;
5. mount persistent application state under `/data`.

## Open the application

Open:

```text
http://localhost:8080
```

On the first startup, configure the dashboard administrator password if authentication has not already been initialized.

## Check container status

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  ps
```

## View logs

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  logs -f
```

To show only the application service logs:

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  logs -f mikrotik-wireguard
```

## Inspect the generated application configuration

To check the effective configuration inside the running container:

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  exec mikrotik-wireguard \
  cat /var/www/html/configs/main.php
```

Be aware that this output contains the RouterOS username and password.

## Test RouterOS REST connectivity

If REST mode is enabled, test HTTPS connectivity from inside the container:

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  exec mikrotik-wireguard \
  curl -vk https://10.0.2.254/rest/system/resource
```

Replace `10.0.2.254` with your configured RouterOS host.

A `401 Unauthorized` response still confirms that TCP connectivity and TLS negotiation are working.

## Persistent data

The Compose configuration uses a Docker volume for `/data`.

The directory contains application runtime state such as:

```text
/data/.admin-hash
/data/.api-token
/data/sessions/
```

This data survives normal container recreation.

## Stop the application

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  down
```

This removes the container and Compose network but preserves the persistent data volume.

## Reset all local application state

To remove the containers and the persistent data volume:

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  down -v
```

The next startup will behave like a fresh installation.

## Rebuild after source changes

```bash
docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  build --no-cache

docker compose \
  --env-file build/.env \
  -f build/compose.yaml \
  up -d
```

## Security notes

The local `.env` file contains RouterOS credentials and must not be committed to Git.

For local testing, credentials are supplied through environment variables. Kubernetes deployments should use Kubernetes Secrets as documented by the Helm chart.

When using REST mode with:

```dotenv
MIKROTIK_SSL_VERIFY=false
```

the application does not verify the RouterOS TLS certificate. This is useful for testing with self-signed certificates but should preferably be replaced by a trusted certificate for production use.

Do not expose the local testing service directly to the public Internet.
