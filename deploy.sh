#!/bin/sh

set -e

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${GREEN}[DEPLOY]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# Check if .env exists, create from .env.example if not
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        log "Creating .env from .env.example..."
        cp .env.example .env
    else
        warn ".env.example not found. Please create .env manually."
        exit 1
    fi
fi

# Prompt for APP_URL only if it's the same as example (http://localhost)
current_app_url=$(grep "^APP_URL=" .env | cut -d '=' -f2- 2>/dev/null || echo "")
if [ "$current_app_url" = "http://localhost" ]; then
    read -p "APP_URL [http://localhost:8000]: " app_url
    app_url=${app_url:-http://localhost:8000}
    sed -i.bak "s|^APP_URL=.*|APP_URL=$app_url|" .env
    log "APP_URL set to: $app_url"
fi

# Clean up backup files created by sed
rm -f .env.bak

# Determine deployment mode (Docker or normal)
DEPLOY_MODE_FILE=".deploy-mode"
if [ -f "$DEPLOY_MODE_FILE" ]; then
    # App was deployed before, use stored preference
    DEPLOY_MODE=$(cat "$DEPLOY_MODE_FILE")
    log "Using stored deployment mode: $DEPLOY_MODE"
else
    # First time deployment, ask user
    log "Choose deployment method:"
    echo "1) Docker (recommended)"
    echo "2) Normal (standard)"
    read -p "Enter choice [1]: " choice
    choice=${choice:-1}
    
    if [ "$choice" = "1" ] || [ "$choice" = "docker" ] || [ "$choice" = "Docker" ]; then
        DEPLOY_MODE="docker"
    else
        DEPLOY_MODE="normal"
    fi
    
    # Store preference for future runs
    echo "$DEPLOY_MODE" > "$DEPLOY_MODE_FILE"
    log "Deployment mode set to: $DEPLOY_MODE (saved for future runs)"
fi

# Git: Always match remote repository exactly (discard all local changes)
if [ -d .git ]; then
    log "Resetting to match remote repository exactly..."

    PROJECT_DIR=$(pwd)
    
    # Remove stale lock files
    rm -f .git/index.lock .git/*.lock 2>/dev/null || true
    
    # Configure git safe directory
    git config --global --add safe.directory "$PROJECT_DIR" 2>/dev/null || true

    # Get current branch name
    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")

    # Fix permissions before git reset (only needed in Docker mode where files are owned by www-data)
    if [ "$DEPLOY_MODE" = "docker" ] && docker ps --format '{{.Names}}' | grep -q "^flusso_app$"; then
        log "Fixing file permissions before git reset..."
        # Change ownership to host user so git commands on host can access files
        docker exec flusso_app chown -R "$(id -u):$(id -g)" /var/www 2>/dev/null || true
    fi

    # Abort any ongoing merge/rebase/cherry-pick operations
    log "Aborting any ongoing git operations..."
    git merge --abort 2>/dev/null || true
    git rebase --abort 2>/dev/null || true
    git cherry-pick --abort 2>/dev/null || true

    # Fetch latest from remote
    log "Fetching latest from origin..."
    git fetch origin || warn "Failed to fetch from origin"

    # Reset hard to remote branch (discards ALL local changes)
    log "Resetting to origin/$CURRENT_BRANCH (discarding all local changes)..."
    git reset --hard "origin/$CURRENT_BRANCH" || {
        warn "Failed to reset to origin/$CURRENT_BRANCH, trying origin/main..."
        git reset --hard origin/main || warn "Failed to reset to origin/main"
    }

    log "Cleaning untracked files..."
    git clean -fd || true

    # Verify we're on the correct branch and up to date
    log "Verifying repository state..."
    git checkout "$CURRENT_BRANCH" 2>/dev/null || git checkout main 2>/dev/null || true
    git reset --hard "origin/$CURRENT_BRANCH" 2>/dev/null || git reset --hard origin/main 2>/dev/null || true

    log "Repository now matches remote exactly"
fi

if [ "$DEPLOY_MODE" = "docker" ]; then
    # Docker deployment
    log "Deploying with Docker..."
    
    # Check if docker-compose is available
    if ! command -v docker-compose >/dev/null 2>&1 && ! command -v docker >/dev/null 2>&1; then
        warn "Docker is not installed. Please install Docker first."
        exit 1
    fi
    
    # Use docker compose (newer) or docker-compose (older)
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        DOCKER_COMPOSE_CMD="docker compose"
    elif command -v docker-compose >/dev/null 2>&1; then
        DOCKER_COMPOSE_CMD="docker-compose"
    else
        warn "docker-compose not found. Please install Docker Compose."
        exit 1
    fi

    # Prompt for APP_PORT if not set or is default
    current_app_port=$(grep "^APP_PORT=" .env | cut -d '=' -f2- 2>/dev/null || echo "")
    if [ -z "$current_app_port" ] || [ "$current_app_port" = "8000" ]; then
        read -p "APP_PORT [8000]: " app_port
        app_port=${app_port:-8000}
        sed -i.bak "s|^APP_PORT=.*|APP_PORT=$app_port|" .env
        log "APP_PORT set to: $app_port"
        rm -f .env.bak
    fi
    
    log "Building and starting Docker containers..."
    $DOCKER_COMPOSE_CMD up -d --build --remove-orphans
    
    log "Docker deployment completed successfully! 🚀"
    log "Containers are running in the background"
else
    # Normal deployment
    log "Deploying with standard method..."
    
    # Install dependencies
    log "Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction

    # Generate key if missing
    if ! grep -q "^APP_KEY=base64:" .env; then
        log "Generating application key..."
        php artisan key:generate --force
    fi

    # Create SQLite database if needed
    if [ ! -f database/database.sqlite ]; then
        log "Creating SQLite database..."
        touch database/database.sqlite
    fi

    # Clear config cache
    log "Clearing configuration cache..."
    php artisan config:clear || true

    # Run migrations
    log "Running migrations..."
    php artisan migrate --force

    # Install NPM dependencies
    log "Installing NPM dependencies..."
    npm ci

    # Build assets
    log "Building assets..."
    npm run build

    # Clear and optimize
    log "Clearing caches..."
    php artisan optimize:clear

    log "Optimizing application..."
    php artisan optimize
    composer dump-autoload --optimize --no-interaction

    # Setup Laravel scheduler cron job
    log "Setting up Laravel scheduler cron job..."
    PROJECT_DIR=$(pwd)
    PHP_PATH=$(which php 2>/dev/null || echo "php")
    CRON_ENTRY="* * * * * cd $PROJECT_DIR && $PHP_PATH artisan schedule:run >> /dev/null 2>&1"
    CRON_FILE=$(crontab -l 2>/dev/null || echo "")
    CRON_SETUP_FAILED=false

    if echo "$CRON_FILE" | grep -q "artisan schedule:run"; then
        log "Laravel scheduler cron job already exists"
    else
        if (crontab -l 2>/dev/null || echo ""; echo "$CRON_ENTRY") | crontab - 2>/dev/null; then
            log "Laravel scheduler cron job added successfully"
        else
            CRON_SETUP_FAILED=true
            warn "Failed to set up Laravel scheduler cron job"
        fi
    fi

    # Setup queue worker auto-start via cron (only if not already running)
    log "Setting up queue worker auto-start..."
    QUEUE_CRON_ENTRY="*/1 * * * * cd $PROJECT_DIR && pgrep -f 'artisan queue:work' > /dev/null || nohup $PHP_PATH artisan queue:work --tries=3 --timeout=90 --sleep=3 > storage/logs/queue-worker.log 2>&1 &"
    QUEUE_CRON_FILE=$(crontab -l 2>/dev/null || echo "")

    if echo "$QUEUE_CRON_FILE" | grep -q "artisan queue:work"; then
        log "Queue worker cron job already exists"
    else
        if (crontab -l 2>/dev/null || echo ""; echo "$QUEUE_CRON_ENTRY") | crontab - 2>/dev/null; then
            log "Queue worker auto-start cron job added successfully"
        else
            CRON_SETUP_FAILED=true
            warn "Failed to set up queue worker cron job"
        fi
    fi

    # Warn if cron setup failed
    if [ "$CRON_SETUP_FAILED" = true ]; then
        warn "Cron job setup failed. You must manually configure cron jobs or use a systemd service,"
        warn "otherwise the scheduler and queue worker will not run automatically."
    fi

    # Web server root check
    log "Web server document root must be set to: $(pwd)/public"
    warn "Ensure your web server (nginx/apache) points to the 'public' directory, not the project root."

    log "Deployment completed successfully! 🚀"
fi
