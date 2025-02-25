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
cp vendor/namingo/plexdns/env-sample .env
cp vendor/namingo/plexdns/demo.php .
```

3. Rename `env-sample` to `.env`:

```sh
mv .env-sample .env
```

4. Edit `.env` to configure your API credentials and database settings.

5. The `demo.php` script demonstrates how to interact with the library, including:

- Creating and managing DNS zones

- Adding, updating, and deleting DNS records

- Listing available DNS providers

## 🌍 Supported Providers

Most DNS providers **require an API key**, while some may need **additional settings** such as authentication credentials or specific server configurations. All required values must be set in the `.env` file.

| Provider    | Required Credentials |
|------------|---------------------|
| **[AnycastDNS](https://anycastdns.app/)** | `API_KEY` |
| **Bind9** | `API_KEY`, `BIND_IP` |
| **Cloudflare** | `EMAIL:API_KEY` |
| **ClouDNS** | `AUTH_ID`, `AUTH_PASSWORD` |
| **Desec** | `API_KEY` |
| **DNSimple** | `API_KEY` |
| **Hetzner** | `API_KEY` |
| **PowerDNS** | `API_KEY`, `POWERDNS_IP` |
| **Vultr** | `API_KEY` |

---

## Acknowledgements

We extend our gratitude to:
- [QCloudns API Client](https://github.com/sussdorf/qcloudns) which served as inspiration for our ClouDNS module.

---

## 📄 License
PlexDNS is licensed under the **MIT License**.

---

## 📩 Contributing
We welcome contributions! Feel free to submit **issues** or **pull requests** to improve the project.

1. Fork the repository.
2. Create a new branch.
3. Make your changes and commit them.
4. Submit a pull request.

---

## 📞 Support
For any issues, please open an issue on GitHub or contact us at **help@namingo.org**.