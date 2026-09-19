# PlexDNS - Multi-Provider DNS Management Tool

PlexDNS is a **unified, multi-provider DNS management tool** that allows users to manage DNS zones and records across multiple DNS hosting providers using a common interface.

## 🚀 Installation

1. **Go to your project directory** and install PlexDNS via **Composer**:

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

## 🌍 Supported Providers

Most DNS providers **require an API key**, while some may need **additional settings** such as authentication credentials or specific server configurations. All required values must be set in the `.env` file.

| Provider    | Credentials in .env | Requirements  | Status | DNSSEC |
|------------|---------------------|------------|---------------------|---------------------|
| **AnycastDNS** | `API_KEY` | | ✅ | ❌ |
| **Bind9** | `API_KEY:BIND_IP` | [bind9-api-server](https://github.com/getnamingo/bind9-api-server)/[bind9-api-server-sqlite](https://github.com/getnamingo/bind9-api-server-sqlite) | ✅ | 🚧 |
| **Bunny** | `API_KEY` | | ✅ | ✅ |
| **Cloudflare** | `EMAIL:API_KEY` or `API_TOKEN` | | ✅ | ❌ |
| **ClouDNS** | `AUTH_ID:AUTH_PASSWORD` | | ✅ | ✅ |
| **Desec** | `API_KEY` | | ✅ | ✅ |
| **DNSimple** | `API_KEY` | | ✅ | ❌ |
| **Hetzner** | `API_KEY` | Hetzner Console project token (read/write) | 🚧 | ❌ |
| **PowerDNS** | `API_KEY:POWERDNS_IP` | gmysql-dnssec=yes in pdns.conf | ✅ | ✅ |
| **Vultr** | `API_KEY` | | ✅ | ❌ |

### Hetzner

The provider uses the [Hetzner Cloud DNS API](https://docs.hetzner.cloud/reference/cloud#zones).
Set `apikey` / `API_KEY` to a **read/write API token from the Hetzner Console project
containing your zones**. Old DNS Console tokens do not work with this API.
Existing zones must be available in that project; the provider addresses them by name
and ignores old DNS Console zone/record IDs.

Zone and record creation, update and deletion are supported. Other previously
unimplemented operations (including synchronization and DNSSEC) remain unsupported.
`createRRset()` still returns a boolean. Its optional ID output is null because the
Cloud API identifies individual records by their value within a name/type RRSet.

`Service` supplies the saved old value for updates/deletes, including MX/SRV priority.
Direct provider callers must supply complete RDATA for MX/SRV deletion and `old_value`
when updating a multi-value RRSet. TXT values are quoted/chunked automatically.
TTL is shared by all values in an RRSet; adding another value requires the existing
TTL. Mutations wait for Hetzner's action to succeed before Service changes local data.

### Testing Provider

`PlexDNS\Providers\Testing` lets projects exercise `PlexDNS\Service` without
contacting a DNS provider. Use it only with a fresh in-memory SQLite connection:

```php
$pdo = new PDO('sqlite::memory:');
$service = new PlexDNS\Service($pdo);
$service->install();

$config = ['provider' => 'Testing'];
```

The SQLite `zones` and `records` tables are the source of truth. This provider
does not publish, resolve, validate, propagate, or persist DNS data and must not
be used in production. Its DNSSEC support only simulates enabled/disabled state;
it does not create keys or DS records.

### Slave Zone Support

Different DNS providers handle slave (secondary) zones differently. **BIND9 and PowerDNS require explicit slave configuration**, meaning you must manually add the slave servers to your `$config` array for them to sync from the master. This involves passing the necessary API details, such as `apikey_nsX` and `bindip_nsX` for BIND9 or `powerdnsip_nsX` for PowerDNS. In contrast, **cloud-based DNS providers handle replication automatically**, so there is no need to configure slave servers manually. Once a zone is added, it is automatically synchronized across their global infrastructure without additional setup.

**BIND9 Example**

```php
$config = [
    'apikey' => 'masterUser:masterPass',  // Master API Key
    'bindip' => '192.168.1.100',  // Master BIND9 server IP

    // Slave 1 (NS2)
    'apikey_ns2' => 'slaveUser1:slavePass1',
    'bindip_ns2' => '192.168.1.101',
    
    // You can add up to 13 slave servers (NS2 to NS13)
];
```

**PowerDNS Example**

```php
$config = [
    'apikey' => 'master_api_key',  // Master PowerDNS API Key
    'powerdnsip' => '127.0.0.1',  // Master PowerDNS IP
    'pdns_master_ip' => '192.168.1.1', // Master IP for slaves to sync from

    // Slave 1 (NS2)
    'apikey_ns2' => 'slave2_api_key',
    'powerdnsip_ns2' => '192.168.1.2',

    // You can add up to 13 slave servers (NS2 to NS13)
];
```

## Acknowledgements

We extend our gratitude to:
- [QCloudns API Client](https://github.com/sussdorf/qcloudns) which served as inspiration for our ClouDNS module.

## 📄 License
PlexDNS is licensed under the **MIT License**.

## 📩 Contributing
We welcome contributions! Feel free to submit **issues** or **pull requests** to improve the project.

1. Fork the repository.
2. Create a new branch.
3. Make your changes and commit them.
4. Submit a pull request.

## 📞 Support
For any issues, please open an issue on GitHub or contact us at **help@namingo.org**.
