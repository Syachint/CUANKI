#!/bin/bash

# CUANKI API Server Deployment Script
# This script downloads all necessary files and deploys the application

set -e  # Exit on any error

GITHUB_RAW_URL="https://raw.githubusercontent.com/Syachint/CUANKI/main"
BRANCH="${1:-main}"  # Allow custom branch, default to main

echo "🚀 Starting CUANKI API Deployment..."
echo "📥 Downloading files from GitHub (branch: $BRANCH)..."

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

# Create necessary directories
echo "📁 Creating directory structure..."
mkdir -p nginx postgres ssl storage/{app/public,framework/{cache,sessions,views},logs} bootstrap/cache public

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
    
    echo ""
    echo "⚠️  IMPORTANT: Configure your environment variables!"
    echo "   Edit .env file with your settings:"
    echo "   - APP_KEY: Generate with 'openssl rand -base64 32' or use online generator"
    echo "   - APP_URL: Your domain (e.g., http://your-domain.com)"
    echo "   - DB_DATABASE: Database name (default: cuanki)"
    echo "   - DB_USERNAME: Database user (default: cuanki_user)"
    echo "   - DB_PASSWORD: Strong database password"
    echo ""
    
    # Interactive setup
    read -p "Do you want to configure .env now? (y/N): " configure_env
    if [[ $configure_env =~ ^[Yy]$ ]]; then
        echo "📝 Opening .env file for editing..."
        ${EDITOR:-nano} .env
    else
        echo "⚠️  Remember to configure .env before running the application!"
        echo "   Edit with: nano .env"
    fi
fi

# Generate APP_KEY if not set
if ! grep -q "APP_KEY=base64:" .env 2>/dev/null; then
    echo "🔑 Generating APP_KEY..."
    if command -v openssl &> /dev/null; then
        APP_KEY="base64:$(openssl rand -base64 32)"
        sed -i "s|APP_KEY=.*|APP_KEY=$APP_KEY|" .env
        echo "✅ APP_KEY generated successfully!"
    else
        echo "⚠️  Please generate APP_KEY manually:"
        echo "   Online: https://generate-random.org/laravel-key-generator"
        echo "   Or use: openssl rand -base64 32"
    fi
fi

# Set proper permissions
echo "🔐 Setting permissions..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Pull latest images
echo "📦 Pulling latest Docker images..."
docker-compose pull

# Stop existing containers
echo "🛑 Stopping existing containers..."
docker-compose down

# Start services
echo "🚀 Starting services..."
docker-compose up -d

# Wait for database to be ready
echo "⏳ Waiting for database to be ready..."
sleep 10

# Run database migrations
echo "🗄️  Running database migrations..."
docker-compose exec -T app php artisan migrate --force

# Clear and cache config
echo "🧹 Clearing cache and optimizing..."
docker-compose exec -T app php artisan config:cache
docker-compose exec -T app php artisan route:cache
docker-compose exec -T app php artisan view:cache

# Check services status
echo "✅ Checking services status..."
docker-compose ps

echo ""
echo "🎉 Deployment completed successfully!"
echo ""
echo "📊 Service Status:"
docker-compose ps
echo ""
echo "🌐 Your API should be available at: $(grep APP_URL .env 2>/dev/null | cut -d '=' -f2 || echo 'Check your .env file')"
echo ""
echo "� Useful Commands:"
echo "   �📊 Check logs: docker-compose logs -f app"
echo "   📊 All logs: docker-compose logs -f"
echo "   🔄 Update app: docker-compose pull app && docker-compose up -d app"
echo "   🗄️  Run migrations: docker-compose exec app php artisan migrate --force"
echo "   🧹 Clear cache: docker-compose exec app php artisan config:clear"
echo "   🛑 Stop all: docker-compose down"
echo "   🔄 Restart: docker-compose restart"
echo ""
echo "� Troubleshooting:"
echo "   📝 Edit .env: nano .env"
echo "   🔍 Check app logs: docker-compose logs app"
echo "   🔍 Check nginx logs: docker-compose logs nginx"
echo "   🔍 Access app shell: docker-compose exec app sh"
echo ""
echo "✅ Setup complete! Your Laravel API is running in Docker containers."