# CUANKI API - Server Deployment Guide

## 🚀 Quick Start

### Prerequisites
- Docker & Docker Compose installed on your server
- Domain/IP address for your server
- Generated APP_KEY for Laravel

### 1. Server Setup

```bash
# Install Docker (Ubuntu/Debian)
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# Reboot or logout/login to apply group changes
```

### 2. Deploy Application

```bash
# Clone/upload project files to server
git clone https://github.com/Syachint/CUANKI.git /opt/cuanki-api
cd /opt/cuanki-api

# Setup environment
cp .env.server .env
nano .env  # Edit with your configuration

# Make deployment script executable
chmod +x deploy.sh

# Deploy
./deploy.sh
```

## 📋 Configuration

### Environment File (.env)
```env
# Application
APP_KEY=base64:your_app_key_here
APP_URL=http://your-domain.com

# Database
DB_DATABASE=cuanki
DB_USERNAME=cuanki_user
DB_PASSWORD=your_secure_password
```

### Generate APP_KEY
```bash
# Option 1: Using Laravel Artisan (if you have PHP locally)
php artisan key:generate --show

# Option 2: Online generator
# Visit: https://generate-random.org/laravel-key-generator

# Option 3: Manual (Linux/Mac)
echo "base64:$(openssl rand -base64 32)"
```

## 🐳 Docker Services

- **app**: Laravel API (Port: internal 9000)
- **nginx**: Web server (Ports: 80, 443)
- **postgres**: Database (Port: 5432)

## 🔧 Management Commands

### Basic Operations
```bash
# Start services
docker-compose up -d

# Stop services
docker-compose down

# Restart services
docker-compose restart

# View logs
docker-compose logs -f app
docker-compose logs -f nginx
docker-compose logs -f postgres
```

### Application Management
```bash
# Run migrations
docker-compose exec app php artisan migrate --force

# Clear cache
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear

# Check application status
docker-compose exec app php artisan --version
```

### Database Management
```bash
# Access database
docker-compose exec postgres psql -U cuanki_user -d cuanki

# Backup database
docker-compose exec postgres pg_dump -U cuanki_user cuanki > backup_$(date +%Y%m%d).sql

# Restore database
docker-compose exec -T postgres psql -U cuanki_user cuanki < backup_file.sql
```

## 🔒 Security Setup

### 1. Firewall Configuration
```bash
# Allow only necessary ports
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp
sudo ufw enable
```

### 2. SSL Certificate (Let's Encrypt)
```bash
# Install Certbot
sudo apt install certbot

# Generate certificate
sudo certbot certonly --standalone -d your-domain.com

# Copy certificates
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem ./ssl/cert.pem
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem ./ssl/private.key
sudo chown $USER:$USER ./ssl/*

# Update nginx config for HTTPS (modify nginx/default.conf)
# Then restart: docker-compose restart nginx
```

## 📊 Monitoring

### Health Check
```bash
# Check if API is responding
curl http://your-domain.com/api/health

# Check services status
docker-compose ps

# Monitor resource usage
docker stats
```

### Logs
```bash
# Application logs
docker-compose logs -f app

# Nginx access logs
docker-compose exec nginx tail -f /var/log/nginx/access.log

# PostgreSQL logs
docker-compose logs postgres
```

## 🔄 Updates

### Update Application
```bash
# Pull latest image
docker-compose pull app

# Restart application
docker-compose up -d app

# Run migrations if needed
docker-compose exec app php artisan migrate --force
```

### Auto Update Script
Create `update.sh`:
```bash
#!/bin/bash
echo "Updating CUANKI API..."
docker-compose pull app
docker-compose up -d app
docker-compose exec app php artisan migrate --force
docker-compose exec app php artisan config:cache
echo "Update completed!"
```

## 🆘 Troubleshooting

### Common Issues

1. **Permission Issues**
```bash
sudo chown -R 1000:1000 storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

2. **Database Connection**
```bash
# Check if PostgreSQL is running
docker-compose ps postgres

# Test connection
docker-compose exec app php artisan tinker
# Then in tinker: DB::connection()->getPdo();
```

3. **Nginx Issues**
```bash
# Check nginx config
docker-compose exec nginx nginx -t

# Restart nginx
docker-compose restart nginx
```

## 📝 File Structure
```
/opt/cuanki-api/
├── docker-compose.yml      # Main compose file
├── .env                    # Environment variables
├── nginx/
│   └── default.conf       # Nginx configuration
├── postgres/
│   └── init.sql          # Database initialization
├── ssl/                   # SSL certificates
├── storage/              # Laravel storage (persistent)
└── deploy.sh            # Deployment script
```

---

🎉 **Your Laravel API is now running in production!**

Access your API at: `http://your-domain.com/api/`