#!/bin/bash

# CUANKI API Server Deployment Script
# This script downloads all necessary files and deploys the application

set -e  # Exit on any error

GITHUB_RAW_URL="https://raw.githubusercontent.com/Syachint/CUANKI/main"
BRANCH="${1:-main}"  # Allow custom branch, default to main

echo "🚀 Starting CUANKI API Deployment..."
echo "📥 Downloading files from GitHub (branch: $BRANCH)..."

# Function to check if user can run Docker without sudo
check_docker_permission() {
    if docker ps >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    echo "💡 Install with: curl -fsSL https://get.docker.com -o get-docker.sh && sudo sh get-docker.sh"
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is not installed. Please install Docker Compose first."
    echo "💡 Install with: sudo curl -L \"https://github.com/docker/compose/releases/latest/download/docker-compose-\$(uname -s)-\$(uname -m)\" -o /usr/local/bin/docker-compose && sudo chmod +x /usr/local/bin/docker-compose"
    exit 1
fi

# Check Docker permissions
if ! check_docker_permission; then
    echo "❌ Permission denied: Cannot access Docker daemon."
    echo "💡 Please run: sudo usermod -aG docker $USER && newgrp docker"
    echo "   Or run this script with: sudo ./deploy.sh"
    exit 1
fi

# Create necessary directories
echo "📁 Creating directory structure..."
mkdir -p nginx postgres ssl

# Create storage directories with proper structure
mkdir -p storage/app/public
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache
mkdir -p public

# Download required files
echo "📥 Downloading configuration files..."

# Download docker-compose.yml
echo "  → docker-compose.yml"
curl -fsSL "$GITHUB_RAW_URL/docker-compose.yml" -o docker-compose.yml || {
    echo "❌ Failed to download docker-compose.yml"
    exit 1
}

# Download nginx configuration
echo "  → nginx/default.conf"
curl -fsSL "$GITHUB_RAW_URL/nginx/default.conf" -o nginx/default.conf || {
    echo "❌ Failed to download nginx configuration"
    exit 1
}

# Download postgres init script
echo "  → postgres/init.sql"
curl -fsSL "$GITHUB_RAW_URL/postgres/init.sql" -o postgres/init.sql || {
    echo "❌ Failed to download postgres init script"
    exit 1
}

# Download environment template
echo "  → .env.server template"
curl -fsSL "$GITHUB_RAW_URL/.env.server" -o .env.server || {
    echo "❌ Failed to download .env template"
    exit 1
}

echo "✅ All files downloaded successfully!"

# Create .env file if not exists
if [ ! -f .env ]; then
    echo "📝 Creating .env file from template..."
    cp .env.server .env
    
    # Auto-detect server IP and set APP_URL
    SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || curl -s ipinfo.io/ip 2>/dev/null || echo "localhost")
    if [ "$SERVER_IP" != "localhost" ]; then
        sed -i "s|APP_URL=http://your-domain.com|APP_URL=http://$SERVER_IP|" .env
        echo "🌐 Auto-detected server IP: $SERVER_IP"
        echo "   APP_URL set to: http://$SERVER_IP"
    fi
    
    echo ""
    echo "⚠️  IMPORTANT: Configure your environment variables!"
    echo "   Current APP_URL: $(grep APP_URL .env | cut -d '=' -f2)"
    echo "   - Change APP_URL if you have a domain"
    echo "   - Update DB_PASSWORD for security"
    echo ""
    
    # Interactive setup
    read -p "Do you want to edit .env now? (y/N): " configure_env
    if [[ $configure_env =~ ^[Yy]$ ]]; then
        echo "📝 Opening .env file for editing..."
        ${EDITOR:-nano} .env
    fi
fi

# Generate APP_KEY if not set
if ! grep -q "APP_KEY=base64:" .env 2>/dev/null || grep -q "your_generated_app_key_here" .env 2>/dev/null; then
    echo "🔑 Generating APP_KEY..."
    if command -v openssl &> /dev/null; then
        APP_KEY="base64:$(openssl rand -base64 32)"
        sed -i "s|APP_KEY=.*|APP_KEY=$APP_KEY|" .env
        echo "✅ APP_KEY generated successfully!"
    else
        echo "⚠️  OpenSSL not found. Using fallback method..."
        APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
        sed -i "s|APP_KEY=.*|APP_KEY=$APP_KEY|" .env
        echo "✅ APP_KEY generated successfully!"
    fi
fi

# Pull latest images first (this ensures images exist)
echo "📦 Pulling latest Docker images..."
docker-compose pull

# Stop existing containers if any
echo "🛑 Stopping existing containers..."
docker-compose down 2>/dev/null || true

# Start services
echo "🚀 Starting services..."
docker-compose up -d

# Wait for services to be ready
echo "⏳ Waiting for services to be ready..."
sleep 15

# Fix permissions using Docker (this avoids host permission issues)
echo "🔐 Setting permissions via Docker..."
docker-compose exec -T app chown -R www-data:www-data /var/www/storage
docker-compose exec -T app chown -R www-data:www-data /var/www/bootstrap/cache
docker-compose exec -T app chmod -R 775 /var/www/storage
docker-compose exec -T app chmod -R 775 /var/www/bootstrap/cache

# Create storage symlink if doesn't exist
echo "🔗 Creating storage symlink..."
docker-compose exec -T app php artisan storage:link 2>/dev/null || true

# Run database migrations
echo "🗄️  Running database migrations..."
docker-compose exec -T app php artisan migrate --force

# Clear and cache config
echo "🧹 Optimizing Laravel..."
docker-compose exec -T app php artisan config:cache
docker-compose exec -T app php artisan route:cache
docker-compose exec -T app php artisan view:cache

# Final permission fix
echo "🔧 Final permission adjustments..."
docker-compose exec -T app chown -R www-data:www-data /var/www/storage
docker-compose exec -T app chown -R www-data:www-data /var/www/bootstrap/cache

# Check services status
echo "✅ Checking services status..."
docker-compose ps

echo ""
echo "🎉 Deployment completed successfully!"
echo ""
echo "📊 Service Status:"
docker-compose ps
echo ""

# Get the actual APP_URL from .env
APP_URL=$(grep APP_URL .env 2>/dev/null | cut -d '=' -f2 || echo "http://localhost")
echo "🌐 Your API is available at: $APP_URL"
echo ""
echo "🔍 Quick Health Check:"
echo "   Test API: curl $APP_URL/api/health || curl $APP_URL"
echo ""
echo "🛠️ Useful Commands:"
echo "   📊 Check logs: docker-compose logs -f app"
echo "   📊 All logs: docker-compose logs -f"
echo "   🔄 Update app: docker-compose pull app && docker-compose up -d app"
echo "   🗄️  Run migrations: docker-compose exec app php artisan migrate"
echo "   🧹 Clear cache: docker-compose exec app php artisan config:clear"
echo "   🛑 Stop all: docker-compose down"
echo "   🔄 Restart: docker-compose restart"
echo "   🔍 Access app shell: docker-compose exec app sh"
echo ""
echo "🔧 Troubleshooting:"
echo "   📝 Edit .env: nano .env"
echo "   🔍 Check app logs: docker-compose logs app"
echo "   🔍 Check nginx logs: docker-compose logs nginx"
echo "   🔍 Check postgres logs: docker-compose logs postgres"
echo ""
echo "🔐 Security Notes:"
echo "   - Change default DB_PASSWORD in .env"
echo "   - Add SSL certificate to ssl/ directory for HTTPS"
echo "   - Configure firewall to allow only necessary ports"
echo ""
echo "✅ CUANKI API is now running! 🚀"