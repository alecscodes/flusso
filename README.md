# 💳 Flusso

> **Personal finance** built with Laravel & Vue  
> Track accounts, transactions, recurring payments, and manage your money.
---

## 📋 Table of Contents

- [Quick Start](#-quick-start)
  - [Docker](#-docker)
  - [Hosting](#-hosting)
- [Features](#-features)
- [Configuration](#️-configuration)
  - [Registration Control](#-registration-control)
  - [IP Banning](#-ip-banning)
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

### 🐳 Docker

**Install**

```bash
git clone https://github.com/alecscodes/flusso.git && cd flusso
cp .env.example .env
sed -i "s|^APP_KEY=$|APP_KEY=base64:$(openssl rand -base64 32)|" .env
docker compose build
docker compose run --rm app php artisan migrate --force
docker compose up -d --wait
docker compose exec app php artisan optimize
```

The app runs on port 80. To use another port, set `APP_PORT` in `.env`.

**Update**

```bash
git pull
docker compose build
docker compose run --rm app php artisan migrate --force
docker compose up -d --wait
docker compose exec app php artisan optimize
```

Your data (database and uploaded files) is kept in a Docker volume, so updates never delete it.

Update not showing up? Rebuild with `docker compose build --no-cache`.

### 🖥 Hosting

**Install**

1. Clone the project outside the public web folder (e.g. not inside `public_html` or `/var/www/html`) and set it up:

   ```bash
   git clone https://github.com/alecscodes/flusso.git ~/flusso && cd ~/flusso
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && php artisan key:generate
   npm ci
   npm run build
   php artisan migrate --force
   php artisan optimize
   chmod -R 775 storage bootstrap/cache
   ```

2. Point your domain's document root to `~/flusso/public` (cPanel: Domains; nginx: `root`; Apache: `DocumentRoot`).
3. Add this cron job (`crontab -e`, or Cron Jobs in cPanel):

   ```
   * * * * * cd ~/flusso && php artisan schedule:run >> /dev/null 2>&1
   ```

**Update**

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan reload
```

Something broken after an update? Run `php artisan optimize:clear`, then `php artisan optimize`.

## ✨ Features

- 💰 **Multi-account management** – bank accounts, cards, and wallets in different currencies
- 📊 **Dashboard** – financial overview and summaries
- 📁 **Categories** – organize transactions by category
- 🔄 **Recurring payments** – track bills and subscriptions
- 📱 **Payments** – mark recurring payments as paid or unpaid
- 🔐 **Two-factor authentication** for enhanced security
- 🌙 **Dark mode** for comfortable use
- 📱 **Mobile-first responsive design**
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

---

## 🔧 Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan ip:unban <ip>` | Unban a specific IP address |
| `php artisan ip:unban --all` | Unban all banned IP addresses |

---

## 🛠 Tech Stack

| Category | Technology |
|----------|-----------|
| **Backend** | Laravel 12 · PHP 8.4+ |
| **Frontend** | Vue 3 · Inertia v2 · Tailwind CSS v4 |
| **Database** | SQLite (MySQL/PostgreSQL supported) |
| **Deployment** | Docker · Hosting |
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
