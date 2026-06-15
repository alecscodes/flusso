# 💳 Flusso

> **Personal finance** built with Laravel & Vue  
> Track accounts, transactions, recurring payments, and manage your money.
---

## 📋 Table of Contents

- [Quick Start](#-quick-start)
  - [Deploy](#-deploy)
- [Features](#-features)
- [Configuration](#️-configuration)
  - [Registration Control](#-registration-control)
  - [IP Banning](#-ip-banning)
  - [Automatic Updates](#-automatic-updates)
- [Artisan Commands](#-artisan-commands)
- [Tech Stack](#-tech-stack)
- [Development](#-development)
  - [Running Tests](#running-tests)
  - [Code Quality](#code-quality)
  - [Frontend Development](#frontend-development)
- [License](#-license)
- [Support](#-support)

---

## 🚀 Quick Start

### 📦 Deploy

```bash
git clone https://github.com/alecscodes/flusso.git
cd flusso
./deploy.sh
```

The `deploy.sh` script will:

- Create `.env` from `.env.example` if missing
- Start Docker containers (when Docker is available) or deploy directly on the host
- Run `php artisan app:deploy` (git sync, dependencies, migrations, optimization)

**Docker:** one shared image, dependencies in Docker volumes.

**Standard (cPanel/VPS):** same deploy command on the host, plus cron for scheduler and queue.

To update an existing installation, run `./deploy.sh` or `php artisan app:deploy`.

## ✨ Features

- 💰 **Multi-account management** – bank accounts, cards, and wallets in different currencies
- 📊 **Dashboard** – financial overview and summaries
- 📁 **Categories** – organize transactions by category
- 🔄 **Recurring payments** – track bills and subscriptions
- 📱 **Payments** – mark recurring payments as paid or unpaid
- 🔐 **Two-factor authentication** for enhanced security
- 🌙 **Dark mode** for comfortable use
- 📱 **Mobile-first responsive design**
- 🔄 **Automatic updates** - checks every five minutes via scheduler; run `./deploy.sh` for manual updates
- 🚫 **Bot blocking** – blocks crawlers and adds noindex headers
- 🛡️ **IP banning** – automatic ban on failed logins and suspicious paths

---

## ⚙️ Configuration

### 👥 Registration Control

- Registration is **automatically enabled** when no users exist (initial setup)
- Registration is **automatically disabled** after the first user is created
- Manual control available via **Settings → Registration**

### 🚫 IP Banning

Flusso automatically bans IPs for suspicious activity:

**Automatic bans triggered by:**

- 2 failed login attempts
- Accessing non-existent routes (e.g. `/wp-admin`)
- Automatically detects and bans related IPs (client, forwarded, proxy, server)

**Unban commands:**

```bash
# Unban a specific IP
php artisan ip:unban 192.168.1.100

# Unban all IPs
php artisan ip:unban --all
```

You can also unban from **Settings → Banned IPs** in the dashboard.

### 🔄 Automatic Updates

Flusso checks for and applies updates every five minutes via the Laravel scheduler:

- **Lightweight checks**: uses `git ls-remote` (no `git fetch` on every check)
- **Smart skipping**: updates are skipped when the local commit matches remote
- **Auto-updates**: `app:deploy --if-outdated` runs every five minutes via the scheduler
- **Manual update**: run `./deploy.sh` or `php artisan app:deploy`

---

## 🔧 Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan app:deploy` | Deploy the application (git sync, dependencies, migrations, optimization) |
| `php artisan app:deploy --if-outdated` | Deploy only when remote has new commits (runs automatically every five minutes) |
| `php artisan ip:unban <ip>` | Unban a specific IP address |
| `php artisan ip:unban --all` | Unban all banned IP addresses |

---

## 🛠 Tech Stack

| Category | Technology |
|----------|-----------|
| **Backend** | Laravel 12 · PHP 8.4+ |
| **Frontend** | Vue 3 · Inertia v2 · Tailwind CSS v4 |
| **Database** | SQLite (MySQL/PostgreSQL supported) |
| **Deployment** | Docker · Standard Hosting |
| **Testing** | Pest PHP v4 |
| **Code Quality** | Laravel Pint · ESLint · Prettier |

---

## 🧪 Development

For local development:

```bash
git clone https://github.com/alecscodes/flusso.git
cd flusso
composer install && npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm run build && composer run dev
```

Visit `http://localhost:8000` to access the application.

### Running Tests

```bash
php artisan test          # Run all tests
```

### Code Quality

```bash
vendor/bin/pint           # Format code with Laravel Pint
npm run lint              # Lint and fix JavaScript/TypeScript/Vue code (ESLint)
npm run format            # Format frontend code (Prettier)
npm run format:check      # Check frontend code formatting (Prettier)
```

### Frontend Development

```bash
npm run dev              # Start Vite dev server with hot reload
npm run build            # Build for production
```

---

## 📄 License

This project is open-sourced software licensed under the [MIT License](LICENSE).

---

## ⚠️ Disclaimer

Flusso is provided **"as is"** without warranty of any kind. For important financial decisions, always verify data and maintain your own records.

---

## 💬 Support

Need help? Found a bug? Have a feature request?

- 🐛 [Report an issue](https://github.com/alecscodes/flusso/issues)
- 💡 [Request a feature](https://github.com/alecscodes/flusso/issues/new)

---

<div align="center">

**Made with ❤️ for personal finance**

</div>
