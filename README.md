# Cardo DNS - Multi-Provider DNS Management Tool

Cardo DNS is a **unified, multi-provider DNS management tool** that allows users to manage DNS zones and records across multiple DNS hosting providers using a common interface.

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

## Installation

1. **Go to your project directory** and install Cardo DNS via **Composer**:

```sh
cd /path/to/your/project
composer require namingo/plexdns
```

2. Copy the sample configuration files from `vendor/namingo/plexdns` to your project root:

```sh
cp vendor/namingo/plexdns/env-sample .
cp vendor/namingo/plexdns/demo.php .
```

3. Move contents of `env-sample` to your project `.env` file.

4. Edit `.env` to configure your API credentials and database settings.

5. The `demo.php` script demonstrates how to interact with the library, including:

- Creating and managing DNS zones

- Adding, updating, and deleting DNS records

- Listing available DNS providers

## HTTP API

Cardo DNS also includes an optional Swoole-based HTTP API in [`api/`](api/), integrating the former `plexdns-api` service directly into this repository. See [`api.md`](api.md) for setup, endpoints, DNSSEC operations, systemd, and reverse-proxy examples.

## Supported Providers

Most DNS providers **require an API key**, while some may need **additional settings** such as authentication credentials or specific server configurations. All required values must be set in the `.env` file.

| Provider    | Credentials in .env | Requirements  | Status | DNSSEC |
|------------|---------------------|------------|---------------------|---------------------|
| **AnycastDNS** | `API_KEY` | | ✅ | ❌ |
| **Bind9** | `API_KEY:BIND_IP` | [bind9-api](https://github.com/getnamingo/bind9-api) | ✅ | 🚧 |
| **Bunny** | `API_KEY` | | ✅ | ✅ |
| **Cloudflare** | `EMAIL:API_KEY` or `API_TOKEN` | | ✅ | ✅ |
| **ClouDNS** | `AUTH_ID:AUTH_PASSWORD` | | ✅ | ✅ |
| **Desec** | `API_KEY` | | ✅ | ✅ |
| **DNSimple** | `API_KEY` | Account access token | ✅ | ✅ |
| **DigitalOcean** | `API_KEY` | Personal access token with domain access | ✅ | ❌ |
| **Gandi LiveDNS** | `API_KEY` | PAT Bearer token | ✅ | ✅ |
| **Hetzner** | `API_KEY` | Hetzner Console project token | ✅ | ❌ |
| **PowerDNS** | `API_KEY:POWERDNS_IP` | [installer](bin/install-powerdns-ubuntu-26.04.sh) | ✅ | ✅ |
| **Scaleway** | `API_KEY:PROJECT_ID` | Secret key + project ID | ✅ | ✅ |
| **Vultr** | `API_KEY` | | ✅ | ✅ |

### Testing Provider

`Namingo\Cardo\DNS\Providers\Testing` lets projects exercise `Namingo\Cardo\DNS\Service` without
contacting a DNS provider. Use it only with a fresh in-memory SQLite connection:

```php
$pdo = new PDO('sqlite::memory:');
$service = new Namingo\Cardo\DNS\Service($pdo);
$service->install();

$config = ['provider' => 'Testing'];
```

The SQLite `zones` and `records` tables are the source of truth. This provider
does not publish, resolve, validate, propagate, or persist DNS data and must not
be used in production. Its DNSSEC support only simulates enabled/disabled state;
it does not create keys or DS records.

### Secondary Zone Support

Different DNS providers handle secondary zones differently. **BIND9 and PowerDNS require explicit secondary configuration**, meaning you must manually add the secondary servers to your `$config` array for them to sync from the primary. This involves passing the necessary API details, such as `apikey_nsX` and `bindip_nsX` for BIND9 or `powerdnsip_nsX` for PowerDNS. In contrast, **cloud-based DNS providers handle replication automatically**, so there is no need to configure secondary servers manually. Once a zone is added, it is automatically synchronized across their global infrastructure without additional setup.

**BIND9 Example**

```php
$config = [
    'apikey' => 'primaryUser:primaryPass',  // Primary API Key
    'bindip' => '192.168.1.100',  // Primary BIND9 server IP

    // Secondary 1 (NS2)
    'apikey_ns2' => 'secondaryUser1:secondaryPass1',
    'bindip_ns2' => '192.168.1.101',
    
    // You can add up to 13 secondary servers (NS2 to NS13)
];
```

**PowerDNS Example**

```php
$config = [
    'apikey' => 'primary_api_key',  // Primary PowerDNS API Key
    'powerdnsip' => '127.0.0.1',  // Primary PowerDNS IP
    'pdns_master_ip' => '192.168.1.1', // Primary IP for secondaries to sync from

    // Secondary 1 (NS2)
    'apikey_ns2' => 'secondary2_api_key',
    'powerdnsip_ns2' => '192.168.1.2',

    // You can add up to 13 secondary servers (NS2 to NS13)
];
```

## Support

Need help, found a bug, or have an idea for Cardo DNS?

- **Email:** [help@namingo.org](mailto:help@namingo.org)
- **Discord:** Join the community on [Discord](https://discord.gg/97R9VCrWgc)
- **GitHub Issues:** Report bugs or request features in [GitHub Issues](https://github.com/getnamingo/cardo-dns/issues)

Questions, feedback, and contributions are always welcome.

## Acknowledgements

Thanks to the [QCloudns API Client](https://github.com/sussdorf/qcloudns), which inspired our ClouDNS module.

## Support This Project

If you find Cardo DNS useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Cardo DNS is licensed under the MIT License.