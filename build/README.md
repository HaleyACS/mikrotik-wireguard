# Container image

This directory contains the container build for MikroTik WireGuard Peer Manager.

The image runs the existing PHP application on Apache/PHP 8.3 and exposes HTTP on port `8080`.

## Build

Run the build from the repository root:

```bash
docker build \
  -f build/Dockerfile \
  -t mikrotik-wireguard:latest \
  .
```

The Docker build context must be the repository root so the application source can be copied into the image.

## Run locally

Create a directory for persistent application state:

```bash
mkdir -p ./data
```

Run the container:

```bash
docker run --rm \
  --name mikrotik-wireguard \
  -p 8080:8080 \
  -v "$(pwd)/data:/data" \
  mikrotik-wireguard:latest
```

Open:

```text
http://localhost:8080
```

Router configuration must be provided under `/var/www/html/configs`.

For Kubernetes deployments, use the Helm chart in `helm-chart/`.

## Persistent data

The application normally stores dashboard authentication data in the application document root.

The container persists the following files under `/data`:

```text
/data/.admin-hash
/data/.api-token
/data/sessions/
```

The entrypoint links `.admin-hash` and `.api-token` back into the application document root.

The symlinks are owned by `www-data` so Linux protected-symlink settings do not prevent Apache/PHP from accessing the files.

## RouterOS API modes

The application supports:

* RouterOS REST API
* RouterOS native API

REST mode communicates with RouterOS using HTTPS.

Native API support requires Python and `librouteros` in the image.

The RouterOS account must have the permissions required by the selected API mode and the operations performed by the application.

For REST-based peer management this normally includes at least:

```text
read,write,rest-api
```

Use the minimum permissions appropriate for your installation.

## TLS

When using RouterOS REST over HTTPS, certificate verification should preferably remain enabled.

If RouterOS uses a self-signed certificate during initial testing, verification can be disabled in the server configuration:

```php
'ssl_verify' => false,
```

For production, using a certificate trusted by the container is recommended.

## Image registry

Push the image to a registry of your choice to use it.

```bash
docker build \
  -f build/Dockerfile \
  -t mikrotik-wireguard:latest \
  .

docker tag  mikrotik-wireguard:latest registry.domain.tld/mikrotik-wireguard:TAG
docker push registry.domain.tld/mikrotik-wireguard:TAG
```

Use immutable image tags for production deployments instead of relying only on `latest`.



## Helm

See:

```text
helm-chart/README.md
```

for Kubernetes deployment instructions.
