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

- Set up `.env` and prompt for `APP_URL` (and `APP_PORT` when using Docker)
- Ask you to choose between **Docker** or **Standard** deployment (first time only)
- Remember your choice for future runs (stored in `.deploy-mode`)
- Reset the repository to match the remote exactly, then deploy or update

Use the same command for both initial deploy and subsequent updates.

## ✨ Features

- 💰 **Multi-account management** – bank accounts, cards, and wallets in different currencies
- 📊 **Dashboard** – financial overview and summaries
- 📁 **Categories** – organize transactions by category
- 🔄 **Recurring payments** – track bills and subscriptions
- 📱 **Payments** – mark recurring payments as paid or unpaid
- 🔐 **Two-factor authentication** for enhanced security
- 🌙 **Dark mode** for comfortable use
- 📱 **Mobile-first responsive design**
- 🔄 **Automatic updates** – checks for updates every minute via scheduler
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

Flusso checks for and applies updates every minute via the Laravel scheduler:

- **Autonomous updates**: The application checks for new commits from the Git repository every minute
- **Smart skipping**: Updates are skipped if no new commits are available
- **Docker support**: In Docker, updates reset the repo and run deployment steps inside the container
- **Update process**: Pulls changes, installs dependencies, builds assets, runs migrations, and optimizes cache

You can also manually trigger an update:

```bash
php artisan git:update
```

---

## 🔧 Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan git:update` | Manually trigger application update from Git repository (runs automatically every minute) |
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
