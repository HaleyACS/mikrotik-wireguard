# Helm chart

This directory contains a Helm chart for deploying MikroTik WireGuard Peer Manager on Kubernetes.

## Requirements

* Kubernetes
* Helm 3
* A built/published MikroTik WireGuard container image
* Network connectivity from Kubernetes pods to the MikroTik RouterOS management address
* A Kubernetes Secret containing the RouterOS username and password
* Optional persistent storage for dashboard authentication state

## Router credentials

RouterOS credentials are intentionally not stored directly in Helm values.

Create a Secret:

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: mikrotik-wireguard-credentials
  namespace: mikrotik-wg
type: Opaque
stringData:
  username: "wgapi"
  password: "CHANGE_ME"
```

Apply it:

```bash
kubectl apply -f helm-chart/examples/router-credentials-secret.yaml
```

The Secret must exist in the same namespace as the Helm release.

## Example configuration

Create a values file:

```yaml
image:
  repository: repo.domain.tld/mikrotik-wireguard
  tag: latest

config:
  servers:
    main:
      credentialsSecret:
        name: mikrotik-wireguard-credentials

      host: "10.0.0.254"
      api_mode: "rest"
      ssl_verify: false

      interface: "wireguard"
      subnet: "10.0.1.0/24"
      server_ip: "10.0.1.1"

      endpoint: "vpn.example.com:51820"

      client_dns:
        - "10.0.0.254"
        - "1.1.1.1"

      client_allowed_ips: "10.0.1.0/24, 10.0.0.0/24"
```

`client_dns` supports either a YAML list or a comma-separated string.

`client_export_metadata` defaults to `true` and requires RouterOS 7.21 or newer on the server CHR. Set it to `false` for older RouterOS versions; generated `.conf` and `.rsc` files remain available.

A generated WireGuard client configuration can therefore contain:

```ini
[Interface]
DNS = 10.0.0.254, 1.1.1.1

[Peer]
Endpoint = vpn.example.com:51820
AllowedIPs = 10.0.1.0/24, 10.0.0.0/24
```

## Install

```bash
helm upgrade --install mikrotik-wireguard \
  ./helm-chart \
  --namespace mikrotik-wg \
  --create-namespace \
  -f values-production.yaml
```

## Upgrade

```bash
helm upgrade mikrotik-wireguard \
  ./helm-chart \
  --namespace mikrotik-wg \
  -f values-production.yaml
```

## ServiceAccount

Use an existing ServiceAccount:

```yaml
serviceAccount:
  create: false
  name: service-account
```

Or allow the chart to create one:

```yaml
serviceAccount:
  create: true
  name: mikrotik-wireguard
```

For private container registries, attach the registry pull Secret to the selected ServiceAccount.

Example:

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: service-account
  namespace: mikrotik-wg
imagePullSecrets:
  - name: repo-access
```

## Persistence

The application persists its dashboard authentication state and PHP sessions under `/data`.

Let the chart create a PVC:

```yaml
persistence:
  enabled: true
  storageClass: longhorn
  size: 1Gi
```

Or use an existing PVC:

```yaml
persistence:
  enabled: true
  existingClaim: mikrotik-wireguard-data
```

When using a manually mounted shared filesystem such as NFS mounted at `/data` on every Kubernetes node, a static PV/PVC may also be used.

## Ingress

Example nginx Ingress with cert-manager:

```yaml
ingress:
  enabled: true
  className: nginx

  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
    nginx.ingress.kubernetes.io/whitelist-source-range: "10.0.0.0/24,10.0.1.0/24"

  hosts:
    - host: wireguard-ui.example.com
      paths:
        - path: /
          pathType: Prefix

  tls:
    - secretName: wireguard-ui-tls
      hosts:
        - wireguard-ui.example.com
```

## Dashboard authentication

The dashboard stores its bcrypt password hash in `.admin-hash`.

The container persists that file under `/data/.admin-hash`.

The chart can also bootstrap authentication from an existing Kubernetes Secret.

## Multiple routers

Multiple RouterOS servers can be configured:

```yaml
config:
  servers:
    home:
      credentialsSecret:
        name: home-router-credentials
      host: "192.168.88.1"
      api_mode: rest
      interface: wireguard
      subnet: "10.50.0.0/24"
      server_ip: "10.50.0.1"
      endpoint: "home.example.com:51820"

    office:
      credentialsSecret:
        name: office-router-credentials
      host: "10.20.0.1"
      api_mode: rest
      interface: wireguard
      subnet: "10.60.0.0/24"
      server_ip: "10.60.0.1"
      endpoint: "office.example.com:51820"
```

Each router can use a separate Kubernetes Secret.

## Validate the chart

Before installing:

```bash
helm lint ./helm-chart \
  -f helm-chart/examples/values-production.yaml
```

Render the generated manifests:

```bash
helm template mikrotik-wireguard \
  ./helm-chart \
  --namespace mikrotik-wg \
  -f helm-chart/examples/values-production.yaml
```

## Security notes

Do not commit production RouterOS passwords, dashboard password hashes, API tokens, TLS private keys, or Kubernetes Secrets containing real credentials.

For GitOps environments, use an encrypted Secret mechanism such as SOPS, Sealed Secrets, or External Secrets.

Restrict access to the dashboard using network policy, Ingress source restrictions, authentication, or another appropriate security layer.
