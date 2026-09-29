# Cardo DNS HTTP API

Cardo DNS includes an optional JSON HTTP API under [`api/`](api/). It is the integrated successor to the separate `plexdns-api` project and uses the same `Namingo\Cardo\DNS\Service`, database schema, provider implementations, record-ID handling, synchronization, and DNSSEC support as the library itself.

The API is designed to run as a small Swoole service, normally bound to localhost and published through a TLS reverse proxy such as Caddy or nginx.

## Requirements

- PHP 8.3 or newer
- PHP Swoole extension
- Composer dependencies installed in the Cardo DNS project root
- MySQL, PostgreSQL, or SQLite
- Credentials for one supported Cardo DNS provider

The API deliberately uses the root `vendor/autoload.php`, so the library and HTTP service cannot drift onto different Cardo versions.

## Configuration

Copy the sample configuration to the project root:

```sh
cp api/env-sample .env
```

At minimum configure:

```dotenv
API_PROVIDER=DigitalOcean
API_TOKEN=replace-with-a-long-random-token
API_HOST=127.0.0.1
API_PORT=9501

DB_TYPE=mysql
DB_HOST=127.0.0.1
DB_NAME=cardodns
DB_USER=cardodns
DB_PASS=change-me

DNS_DIGITALOCEAN_API_KEY=your-provider-token
```

`API_PROVIDER` accepts: `AnycastDNS`, `Bind`, `Bunny`, `Cloudflare`, `ClouDNS`, `Desec`, `DNSimple`, `DigitalOcean`, `GandiLiveDNS`, `Hetzner`, `PowerDNS`, `Scaleway`, `Testing`, and `Vultr`.

For `API_PROVIDER=Testing`, use `DB_TYPE=sqlite` with `SQLITE_PATH=:memory:`. The Testing provider intentionally refuses file-backed SQLite databases.

Provider credentials use the same `DNS_<PROVIDER>_*` convention as the main [`env-sample`](env-sample). Credentials remain server-side. Request bodies cannot replace the configured provider or its credentials.

The daemon manages one provider per process. To expose multiple providers, run separate instances with different environments and ports.

## Run

```sh
php api/start_api.php
```

The default listener is `127.0.0.1:9501`. Set `API_HOST=0.0.0.0` only when direct network exposure is intentional and protected appropriately.

Health check, with no authentication required:

```sh
curl http://127.0.0.1:9501/health
```

All management endpoints require either:

```text
Authorization: Bearer <API_TOKEN>
```

or:

```text
X-API-Token: <API_TOKEN>
```

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Process health check |
| `POST` | `/install` | Create Cardo DNS database tables |
| `POST` | `/uninstall` | Drop Cardo DNS database tables |
| `POST` | `/domain` | Create a DNS zone |
| `DELETE` | `/domain` | Delete a DNS zone |
| `POST` | `/record` | Add a DNS record |
| `PUT` | `/record` | Update a DNS record by local `record_id` |
| `DELETE` | `/record` | Delete a DNS record by local `record_id` |
| `POST` | `/sync` | Refresh the local record cache where supported |
| `GET` | `/dnssec/capabilities` | Provider DNSSEC capabilities |
| `GET` | `/dnssec/status` | DNSSEC status for a zone |
| `GET` | `/dnssec/ds` | DS records for a zone |
| `POST` | `/dnssec/enable` | Enable DNSSEC |
| `POST` | `/dnssec/disable` | Disable DNSSEC where supported |

Provider limitations are the same as when using Cardo DNS directly.

## Database setup

```sh
curl -X POST \
  -H "Authorization: Bearer $API_TOKEN" \
  http://127.0.0.1:9501/install
```

`POST /uninstall` is retained for compatibility with the former PlexDNS API and is destructive.

## Create a domain

```sh
curl -X POST http://127.0.0.1:9501/domain \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": 1,
    "domain_name": "example.com"
  }'
```

For compatibility with older `plexdns-api` clients, `domain_name` may also be supplied as `config.domain_name`. Any provider or credential values inside client-supplied `config` are ignored.

## Delete a domain

```sh
curl -X DELETE http://127.0.0.1:9501/domain \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"domain_name":"example.com"}'
```

## Add a record

```sh
curl -X POST http://127.0.0.1:9501/record \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domain_name": "example.com",
    "record_name": "www",
    "record_type": "A",
    "record_value": "192.0.2.10",
    "record_ttl": 3600
  }'
```

The returned `record_id` is Cardo's local database ID. Provider record IDs, when available, remain managed internally by Cardo DNS.

For `MX`, also send `record_priority`. For `SRV`, send `record_priority`, `record_weight`, and `record_port`. `CAA` may additionally use `record_flags` and `record_tag`.

## Update a record

```sh
curl -X PUT http://127.0.0.1:9501/record \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domain_name": "example.com",
    "record_id": 12,
    "record_name": "www",
    "record_type": "A",
    "record_value": "192.0.2.20",
    "record_ttl": 3600
  }'
```

## Delete a record

```sh
curl -X DELETE http://127.0.0.1:9501/record \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domain_name": "example.com",
    "record_id": 12
  }'
```

## Synchronize records

```sh
curl -X POST http://127.0.0.1:9501/sync \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"domain_name":"example.com"}'
```

## DNSSEC

Capabilities:

```sh
curl -H "Authorization: Bearer $API_TOKEN" \
  http://127.0.0.1:9501/dnssec/capabilities
```

Status and DS records:

```sh
curl -H "Authorization: Bearer $API_TOKEN" \
  'http://127.0.0.1:9501/dnssec/status?domain_name=example.com'

curl -H "Authorization: Bearer $API_TOKEN" \
  'http://127.0.0.1:9501/dnssec/ds?domain_name=example.com'
```

Enable or disable:

```sh
curl -X POST http://127.0.0.1:9501/dnssec/enable \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"domain_name":"example.com"}'

curl -X POST http://127.0.0.1:9501/dnssec/disable \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"domain_name":"example.com"}'
```

Before disabling DNSSEC, remove the parent DS record where required by the provider/registry workflow.

## systemd

A sample unit is included at [`api/cardo-dns-api.service`](api/cardo-dns-api.service):

```sh
sudo cp api/cardo-dns-api.service /etc/systemd/system/
sudo mkdir -p /var/log/cardo-dns
sudo chown www-data:www-data /var/log/cardo-dns
sudo systemctl daemon-reload
sudo systemctl enable --now cardo-dns-api.service
```

If you use SQLite, the service user must also have write access to the SQLite database directory.

## Caddy

```caddyfile
cardodns.example.com {
    reverse_proxy 127.0.0.1:9501
    encode gzip

    header {
        -Server
        Referrer-Policy "no-referrer"
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
    }
}
```

## Logging

Console logs go to stdout/journald. File logs rotate daily and keep 14 files when `API_LOG_PATH` is writable. The default is `/var/log/cardo-dns/api.log`.

Unexpected server errors return an opaque `request_id`; the same ID is logged server-side.

## Migration from plexdns-api

The separate project is no longer needed when using this integrated API. The old `/install`, `/uninstall`, `/domain`, and `/record` routes remain, while Cardo adds synchronization and DNSSEC endpoints.

The integration also fixes several old API issues:

- Uses Cardo's root Composer installation and `Namingo\Cardo\DNS` namespace directly.
- Keeps provider credentials server-side and supports Cardo's current provider-specific options.
- Defaults to localhost instead of all interfaces.
- Fixes the Swoole start callback logger capture.
- Makes the documented reverse-proxy port match the daemon's default `9501`.
- Uses timing-safe token comparison and strict JSON parsing.
- Avoids leaking unexpected provider/database exception details to API clients.
