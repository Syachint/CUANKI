#!/bin/bash

# CUANKI API Server Deployment Script
# This script downloads all necessary files and deploys the application

set -e  # Exit on any error

GITHUB_RAW_URL="https://raw.githubusercontent.com/Syachint/CUANKI/main"
BRANCH="${1:-main}"  # Allow custom branch, default to main

echo "🚀 Starting CUANKI API Deployment..."
echo "📥 Downloading files from GitHub (branch: $BRANCH)..."

# Function to check if running as root
check_root() {
    if [ "$EUID" -eq 0 ]; then
        return 0
    else
        return 1
    fi
}

# Function to check Docker permissions
check_docker_permissions() {
    if docker ps >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Installing Docker..."
    if check_root; then
        curl -fsSL https://get.docker.com -o get-docker.sh
        sh get-docker.sh
        rm get-docker.sh
    else
        echo "💡 Please run as root or install Docker manually:"
        echo "   curl -fsSL https://get.docker.com -o get-docker.sh && sudo sh get-docker.sh"
        exit 1
    fi
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is not installed. Installing..."
    if check_root; then
        curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
        chmod +x /usr/local/bin/docker-compose
    else
        echo "💡 Please run as root or install Docker Compose manually:"
        echo "   sudo curl -L \"https://github.com/docker/compose/releases/latest/download/docker-compose-\$(uname -s)-\$(uname -m)\" -o /usr/local/bin/docker-compose"
        echo "   sudo chmod +x /usr/local/bin/docker-compose"
        exit 1
    fi
fi

# Check Docker permissions
if ! check_docker_permissions; then
    echo "❌ Permission denied: Cannot access Docker daemon."
    echo ""
    echo "💡 Solutions:"
    echo "   1. Run as root: sudo ./deploy.sh"
    echo "   2. Add user to docker group:"
    echo "      sudo usermod -aG docker $USER"
    echo "      newgrp docker"
    echo "      # Then logout and login again"
    echo "   3. Or run specific command as root:"
    echo "      sudo docker-compose up -d"
    echo ""

    read -p "Do you want me to add current user ($USER) to docker group? (y/N): " add_to_group
    if [[ $add_to_group =~ ^[Yy]$ ]]; then
        if check_root; then
            usermod -aG docker $USER
            echo "✅ User $USER added to docker group."
            echo "⚠️  Please logout and login again, then run this script again."
            exit 0
        else
            echo "❌ Need root privileges to add user to docker group."
            echo "💡 Run: sudo usermod -aG docker $USER"
            exit 1
        fi
    else
        echo "❌ Cannot proceed without Docker access. Please fix permissions first."
        exit 1
    fi
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
    echo "   - APP_URL: Your domain (e.g., http://your-domain.com or http://$(curl -s ifconfig.me 2>/dev/null || echo 'YOUR_SERVER_IP'))"
    echo "   - DB_DATABASE: Database name (default: cuanki)"
    echo "   - DB_USERNAME: Database user (default: cuanki_user)"
    echo "   - DB_PASSWORD: Strong database password"
    echo ""

    # Auto-fill APP_URL with server IP if possible
    SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || curl -s ipinfo.io/ip 2>/dev/null || echo "localhost")
    if [ "$SERVER_IP" != "localhost" ]; then
        sed -i "s|APP_URL=http://your-domain.com|APP_URL=http://$SERVER_IP|" .env
        echo "🌐 Auto-detected server IP: $SERVER_IP"
        echo "   APP_URL set to: http://$SERVER_IP"
    fi

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
if ! grep -q "APP_KEY=base64:" .env 2>/dev/null || grep -q "APP_KEY=base64:your_generated_app_key_here" .env 2>/dev/null; then
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

# ========================================
# SSL SETUP
# ========================================
echo ""
echo "🔐 SSL Certificate Setup"
echo "================================"

# Check if SSL certificates already exist
if [ -f "ssl/fullchain.pem" ] && [ -f "ssl/privkey.pem" ]; then
    echo "✅ SSL certificates already exist"
    read -p "Do you want to renew/regenerate SSL certificates? (y/N): " renew_ssl
    if [[ ! $renew_ssl =~ ^[Yy]$ ]]; then
        echo "⏭️  Skipping SSL setup"
        SKIP_SSL=true
    fi
fi

if [ "$SKIP_SSL" != "true" ]; then
    # Get domain from nginx config
    DOMAIN_NAME=$(grep -A1 "listen 443" nginx/default.conf | grep "server_name" | awk '{print $2}' | sed 's/;//')
    
    if [ -z "$DOMAIN_NAME" ] || [ "$DOMAIN_NAME" == "localhost" ] || [[ $DOMAIN_NAME == *"_"* ]]; then
        echo "⚠️  No domain configured in nginx/default.conf"
        read -p "Enter your domain for SSL (e.g., syachdev.site) or leave empty to skip: " DOMAIN_INPUT
        DOMAIN_NAME="$DOMAIN_INPUT"
    fi
    
    if [ ! -z "$DOMAIN_NAME" ]; then
        echo "📋 Domain detected: $DOMAIN_NAME"
        echo "🌐 Server IP: $(curl -s ifconfig.me 2>/dev/null || echo 'Unable to detect')"
        echo ""
        echo "⚠️  IMPORTANT: Make sure DNS A record is pointing to this server!"
        echo ""
        
        read -p "Do you want to setup SSL certificate for $DOMAIN_NAME? (y/N): " setup_ssl
        
        if [[ $setup_ssl =~ ^[Yy]$ ]]; then
            # Check if certbot is installed
            if ! command -v certbot &> /dev/null; then
                echo "📦 Installing certbot..."
                if check_root; then
                    apt-get update -qq
                    apt-get install -y certbot
                else
                    echo "⚠️  Please install certbot manually:"
                    echo "   sudo apt-get update && sudo apt-get install -y certbot"
                    echo ""
                    read -p "Press Enter after installing certbot, or Ctrl+C to skip..."
                fi
            fi
            
            if command -v certbot &> /dev/null; then
                echo ""
                echo "🔐 Generating SSL certificate..."
                echo "⚠️  This requires port 80 to be free and DNS to be configured"
                echo ""
                read -p "Press Enter to continue or Ctrl+C to cancel..."
                
                # Stop nginx container to free port 80
                echo "🛑 Stopping nginx container temporarily..."
                if check_docker_permissions; then
                    docker stop cuanki-nginx 2>/dev/null || true
                else
                    sudo docker stop cuanki-nginx 2>/dev/null || true
                fi
                
                # Generate certificate
                CERT_EMAIL=""
                read -p "Enter email for SSL certificate (or leave empty): " CERT_EMAIL
                
                if [ -z "$CERT_EMAIL" ]; then
                    CERT_CMD="certbot certonly --standalone -d $DOMAIN_NAME -d www.$DOMAIN_NAME --non-interactive --agree-tos --register-unsafely-without-email"
                else
                    CERT_CMD="certbot certonly --standalone -d $DOMAIN_NAME -d www.$DOMAIN_NAME --non-interactive --agree-tos --email $CERT_EMAIL"
                fi
                
                if check_root; then
                    eval $CERT_CMD || {
                        echo "⚠️  Certificate generation failed. Please check:"
                        echo "   1. DNS is properly configured"
                        echo "   2. Port 80 is accessible from internet"
                        echo "   3. No firewall blocking port 80"
                    }
                else
                    sudo $CERT_CMD || {
                        echo "⚠️  Certificate generation failed. Please check:"
                        echo "   1. DNS is properly configured"
                        echo "   2. Port 80 is accessible from internet"
                        echo "   3. No firewall blocking port 80"
                    }
                fi
                
                # Copy certificates if successful
                if [ -d "/etc/letsencrypt/live/$DOMAIN_NAME" ]; then
                    echo "📋 Copying SSL certificates..."
                    mkdir -p ssl  # pastikan folder ssl ada

                    if check_root; then
                        cp "/etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem" ssl/ 2>/dev/null || true
                        cp "/etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem" ssl/ 2>/dev/null || true
                        chown $USER:$USER ssl/*.pem 2>/dev/null || true
                        chmod 644 ssl/*.pem 2>/dev/null || true
                    else
                        sudo cp "/etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem" ssl/ 2>/dev/null || true
                        sudo cp "/etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem" ssl/ 2>/dev/null || true
                        sudo chown $USER:$USER ssl/*.pem 2>/dev/null || true
                        sudo chmod 644 ssl/*.pem 2>/dev/null || true
                    fi

                    if [ -f "ssl/fullchain.pem" ] && [ -f "ssl/privkey.pem" ]; then
                        echo "✅ SSL certificates installed successfully!"
                    else
                        echo "⚠️  SSL already exists in /etc/letsencrypt but was not copied (maybe no new cert issued)."
                        echo "✅ Existing SSL certificate is still valid and active!"
                    fi

                    # Setup auto-renewal
                    echo ""
                    read -p "Do you want to setup automatic SSL renewal? (y/N): " setup_renewal
                    if [[ $setup_renewal =~ ^[Yy]$ ]]; then
                        CURRENT_DIR=$(pwd)
                        cat > /tmp/renew-ssl.sh << EOF
                #!/bin/bash
                docker stop cuanki-nginx 2>/dev/null || true
                certbot renew --quiet
                if [ -d "/etc/letsencrypt/live/$DOMAIN_NAME" ]; then
                    cp /etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem $CURRENT_DIR/ssl/
                    cp /etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem $CURRENT_DIR/ssl/
                    chown $USER:$USER $CURRENT_DIR/ssl/*.pem
                fi
                docker start cuanki-nginx 2>/dev/null || true
                EOF

                        if check_root; then
                            mv /tmp/renew-ssl.sh /usr/local/bin/renew-ssl.sh
                            chmod +x /usr/local/bin/renew-ssl.sh
                            (crontab -l 2>/dev/null | grep -v "renew-ssl.sh"; echo "0 3 * * * /usr/local/bin/renew-ssl.sh >> /var/log/ssl-renew.log 2>&1") | crontab -
                        else
                            sudo mv /tmp/renew-ssl.sh /usr/local/bin/renew-ssl.sh
                            sudo chmod +x /usr/local/bin/renew-ssl.sh
                            (sudo crontab -l 2>/dev/null | grep -v "renew-ssl.sh"; echo "0 3 * * * /usr/local/bin/renew-ssl.sh >> /var/log/ssl-renew.log 2>&1") | sudo crontab -
                        fi

                        echo "✅ Auto-renewal configured! SSL will renew automatically at 3 AM daily"
                    fi
                else
                    echo "❌ SSL certificate generation failed or certificates not found"
                fi

            else
                echo "❌ Certbot not available. Skipping SSL setup."
            fi
        else
            echo "⏭️  Skipping SSL setup"
        fi
    else
        echo "⏭️  No domain provided, skipping SSL setup"
    fi
fi

# Set proper permissions
echo "🔐 Setting permissions..."
sudo chown -R $USER:$USER .
sudo chmod -R 755 .
chmod -R 775 storage 2>/dev/null || sudo chmod -R 775 storage
chmod -R 775 bootstrap/cache 2>/dev/null || sudo chmod -R 775 bootstrap/cache

# Function to run docker-compose with proper permissions
run_docker_compose() {
    if check_docker_permissions; then
        docker-compose "$@"
    else
        echo "⚠️  Running with sudo due to permission issues..."
        sudo docker-compose "$@"
    fi
}

# Pull latest images
echo "📦 Pulling latest Docker images..."
run_docker_compose pull

# Stop existing containers
echo "🛑 Stopping existing containers..."
run_docker_compose down -v

# Start services
echo "🚀 Starting services..."
run_docker_compose up -d --build

# Fix Laravel storage & cache permissions inside container
echo "🔐 Fixing Laravel storage & cache permissions inside container..."
if check_docker_permissions; then
    docker exec cuanki-api sh -c "chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && chmod -R 775 /var/www/storage /var/www/bootstrap/cache" 2>/dev/null || true
else
    sudo docker exec cuanki-api sh -c "chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && chmod -R 775 /var/www/storage /var/www/bootstrap/cache" 2>/dev/null || true
fi

# Wait for database to be ready
echo "⏳ Waiting for database to be ready..."
sleep 15

# Run database migrations
echo "🗄️  Running database migrations..."
if check_docker_permissions; then
    docker-compose exec -T app php artisan migrate:fresh --seed --force
else
    sudo docker-compose exec -T app php artisan migrate:fresh --seed --force
fi

# Clear and cache config
echo "🧹 Clearing cache and optimizing..."
if check_docker_permissions; then
    docker-compose exec -T app php artisan config:cache
    docker-compose exec -T app php artisan route:cache
    docker-compose exec -T app php artisan view:cache
else
    sudo docker-compose exec -T app php artisan config:cache
    sudo docker-compose exec -T app php artisan route:cache
    sudo docker-compose exec -T app php artisan view:cache
fi

# Check services status
echo "✅ Checking services status..."
run_docker_compose ps

echo ""
echo "🎉 Deployment completed successfully!"
echo ""
echo "📊 Service Status:"
run_docker_compose ps
echo ""
FINAL_URL=$(grep APP_URL .env 2>/dev/null | cut -d '=' -f2 || echo 'Check your .env file')
echo "🌐 Your API should be available at: $FINAL_URL"
echo ""

# SSL Status
if [ -f "ssl/fullchain.pem" ] && [ -f "ssl/privkey.pem" ]; then
    echo "🔐 SSL Status: ✅ Configured"
    if [ -f "/usr/local/bin/renew-ssl.sh" ]; then
        echo "   Auto-renewal: ✅ Enabled (runs daily at 3 AM)"
    else
        echo "   Auto-renewal: ❌ Not configured"
    fi
else
    echo "🔐 SSL Status: ⚠️  Not configured"
    echo "   You can setup SSL manually with:"
    echo "   sudo certbot certonly --standalone -d your-domain.com -d www.your-domain.com"
fi
echo ""

echo "🛠️ Useful Commands:"
if check_docker_permissions; then
    echo "   📊 Check logs: docker-compose logs -f app"
    echo "   📊 All logs: docker-compose logs -f"
    echo "   🔄 Update app: docker-compose pull app && docker-compose up -d app"
    echo "   🗄️  Run migrations: docker-compose exec app php artisan migrate --force"
    echo "   🧹 Clear cache: docker-compose exec app php artisan config:clear"
    echo "   🛑 Stop all: docker-compose down"
    echo "   🔄 Restart: docker-compose restart"
    echo "   🔍 Access app shell: docker-compose exec app sh"
else
    echo "   📊 Check logs: sudo docker-compose logs -f app"
    echo "   📊 All logs: sudo docker-compose logs -f"
    echo "   🔄 Update app: sudo docker-compose pull app && sudo docker-compose up -d app"
    echo "   🗄️  Run migrations: sudo docker-compose exec app php artisan migrate --force"
    echo "   🧹 Clear cache: sudo docker-compose exec app php artisan config:clear"
    echo "   🛑 Stop all: sudo docker-compose down"
    echo "   🔄 Restart: sudo docker-compose restart"
    echo "   🔍 Access app shell: sudo docker-compose exec app sh"
fi
echo ""
echo "🔧 Troubleshooting:"
echo "   📝 Edit .env: nano .env"
echo "   📝 Edit nginx config: nano nginx/default.conf"
echo "   🔍 Check app logs: $(check_docker_permissions && echo 'docker-compose' || echo 'sudo docker-compose') logs app"
echo "   🔍 Check nginx logs: $(check_docker_permissions && echo 'docker-compose' || echo 'sudo docker-compose') logs nginx"
echo ""
echo "⚠️  Permission Notice:"
if ! check_docker_permissions; then
    echo "   🔐 You need sudo for Docker commands."
    echo "   🔧 To fix permanently: sudo usermod -aG docker $USER && logout/login"
fi
echo ""
echo "✅ Setup complete! Your Laravel API is running in Docker containers."