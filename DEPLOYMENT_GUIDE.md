# 🚀 CUANKI - Production Deployment Guide

## ⚙️ Environment Configuration untuk Production

### **1. Cookie Security Settings**

Untuk production HTTPS, update file `.env` dengan:

```env
# Production Environment
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Session & Cookie Settings (IMPORTANT!)
SESSION_DOMAIN=yourdomain.com
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true  
SESSION_SAME_SITE=lax
SESSION_PARTITIONED_COOKIE=false

# Security
SESSION_ENCRYPT=true
BCRYPT_ROUNDS=15
```

### **2. Database Configuration**

```env
# Production Database
DB_CONNECTION=pgsql
DB_HOST=your-production-db-host
DB_PORT=5432
DB_DATABASE=cuanki_production
DB_USERNAME=your-db-username
DB_PASSWORD=your-secure-db-password
```

### **3. Cache & Session Storage**

```env
# Recommended for production
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379
```

---

## 🔐 Cookie Behavior per Environment

### **Development (HTTP):**
```php
setcookie(
    'refresh_token', 
    $token, 
    $expires,
    '/',
    null,           // domain: null untuk localhost
    false,          // secure: false untuk HTTP
    true,           // httponly: true untuk security
    false,          // raw
    'lax'           // samesite: lax untuk development
);
```

### **Production (HTTPS):**
```php
setcookie(
    'refresh_token', 
    $token, 
    $expires,
    '/',
    'yourdomain.com', // domain: dari SESSION_DOMAIN
    true,             // secure: true untuk HTTPS
    true,             // httponly: true untuk security  
    false,            // raw
    'lax'             // samesite: lax untuk cross-origin requests
);
```

---

## 🌐 Deployment Platforms

### **1. Vercel/Netlify (Static Frontend + API)**

**.env.production:**
```env
APP_ENV=production
APP_URL=https://your-api-domain.vercel.app
SESSION_DOMAIN=your-api-domain.vercel.app
SESSION_SECURE_COOKIE=true
```

**Frontend Config:**
```javascript
// axios config
const API_BASE = 'https://your-api-domain.vercel.app';
axios.defaults.baseURL = API_BASE;
axios.defaults.withCredentials = true; // PENTING untuk cookies!
```

### **2. DigitalOcean/AWS/VPS**

**.env.production:**
```env
APP_URL=https://api.yourdomain.com
SESSION_DOMAIN=yourdomain.com  # Main domain untuk subdomain sharing
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none         # Jika frontend beda domain
```

### **3. Shared Hosting (cPanel)**

**.env.production:**
```env
APP_URL=https://yourdomain.com/api
SESSION_DOMAIN=yourdomain.com
SESSION_SECURE_COOKIE=true
SESSION_PATH=/api              # Jika API di subfolder
```

---

## 🔄 Cross-Origin Cookie Issues & Solutions

### **Problem: Cookie tidak ter-set di production**

**Cause:** Browser block cookies untuk cross-origin requests

**Solutions:**

#### **Option 1: Same Domain (RECOMMENDED)**
```
Frontend: https://yourdomain.com
Backend:  https://api.yourdomain.com  
```

**.env:**
```env
SESSION_DOMAIN=yourdomain.com    # Allow subdomain sharing
SESSION_SAME_SITE=lax
```

#### **Option 2: Different Domains**
```
Frontend: https://myapp.com
Backend:  https://api-service.com
```

**.env:**
```env
SESSION_DOMAIN=api-service.com
SESSION_SAME_SITE=none
SESSION_SECURE_COOKIE=true      # REQUIRED dengan SameSite=none
```

**Frontend:**
```javascript
// Set CORS credentials
axios.defaults.withCredentials = true;

// Atau untuk fetch
fetch('/api/login', {
    method: 'POST',
    credentials: 'include',  // PENTING!
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(credentials)
});
```

---

## ✅ Production Checklist

### **Before Deployment:**

- [ ] Update `.env` dengan production values
- [ ] Set `SESSION_SECURE_COOKIE=true` untuk HTTPS
- [ ] Set proper `SESSION_DOMAIN`
- [ ] Enable `SESSION_ENCRYPT=true`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure production database
- [ ] Setup Redis untuk cache/sessions
- [ ] Test cookies di staging environment

### **After Deployment:**

- [ ] Test register → cookie set
- [ ] Test login → cookie set  
- [ ] Test refresh token → cookie persistent
- [ ] Test logout → cookie cleared
- [ ] Test cross-origin requests (jika applicable)
- [ ] Monitor Laravel logs untuk cookie errors

### **Frontend Integration:**

- [ ] Set `axios.defaults.withCredentials = true`
- [ ] Handle automatic token refresh
- [ ] Implement proper error handling untuk 401 responses
- [ ] Setup interceptors untuk automatic retry with refresh

---

## 🐛 Common Issues & Debug

### **Issue: "Refresh token not found in cookies"**

**Check:**
1. Browser Developer Tools → Application → Cookies
2. Apakah cookie ter-set dengan nama `refresh_token`?
3. Apakah domain/path cookies match dengan request?

**Debug:**
```php
// Tambah di AuthController untuk debug
\Log::info('Setting cookie', [
    'domain' => config('session.domain'),
    'secure' => config('session.secure'),
    'same_site' => config('session.same_site'),
    'expires_at' => $refreshTokenExpiresAt->toDateTimeString()
]);
```

### **Issue: Cookie hilang setelah refresh**

**Possible Causes:**
- Wrong domain configuration
- Mixed HTTP/HTTPS content  
- Browser blocking cross-site cookies
- Cookie expiry issues

**Solutions:**
1. Check browser console untuk cookie warnings
2. Verify HTTPS configuration
3. Test dengan same-origin setup dulu

---

## 🎯 Final Notes

**AuthController sudah production-ready** dengan:

✅ **Environment-based configuration**
✅ **Automatic HTTPS detection** 
✅ **Proper domain handling**
✅ **Security best practices**
✅ **Consistent cookie setting** across all methods

**Deploy dengan confidence!** 🚀