# 📮 CUANKI API Postman Collection

## 📁 Files yang Disediakan

1. **CUANKI-API-Collection.json** - Collection utama dengan semua API endpoints
2. **CUANKI-Environment.json** - Environment variables untuk development
3. **POSTMAN_SETUP_GUIDE.md** - Panduan setup (file ini)

## 🚀 Cara Setup Postman Collection

### 1. Import Collection
1. Buka Postman
2. Klik **Import** di pojok kiri atas
3. Pilih **File** → Upload **CUANKI-API-Collection.json**
4. Klik **Import**

### 2. Import Environment
1. Di Postman, klik **Environments** di sidebar
2. Klik **Import** 
3. Upload **CUANKI-Environment.json**
4. Pilih environment **"CUANKI Development Environment"** di dropdown

### 3. Set Base URL (Opsional)
Environment default menggunakan `http://localhost:8000`. 

Jika menggunakan Laravel server lain:
- Laragon: `http://cuanki.test` 
- Custom port: `http://localhost:PORT`

Update variable `base_url` di environment sesuai kebutuhan.

## 🔐 Authentication Flow

### 1. Register User (Opsional)
**Endpoint:** `POST /api/register`
```json
{
    "name": "John Doe",
    "email": "john@example.com", 
    "password": "password123",
    "password_confirmation": "password123"
}
```

### 2. Login User ⭐
**Endpoint:** `POST /api/login`
```json
{
    "email": "john@example.com",
    "password": "password123"
}
```

**✨ Auto Token Setup:** Login request sudah include script untuk otomatis save `access_token` ke environment variable `auth_token`.

### 3. Test Authentication
Coba endpoint yang membutuhkan auth seperti:
- `GET /api/user-data`
- `GET /api/greeting-user`

## 📋 Collection Structure

### 🔑 Authentication
- Register User
- Login User (auto-save token)
- Refresh Token
- Logout

### 👤 User Management  
- Get User Data

### 📝 Form Setup
- Get Banks List
- Get Origins
- Form Detail User
- Form Detail Account
- Form Detail Plan

### 📊 Dashboard
- Get Greeting User
- Get Goals Progress
- Get Today Expenses
- Get Daily Saving
- Update Account Balance

### 💰 Transactions
- Get Expense Categories
- Get User Accounts
- Add Income
- Add Expense

### 🔍 Google Authentication
- Google Login
- Google Callback

### 💡 Advice
- Get Advices

## 🎯 Testing Workflow

### Complete Setup Flow:
1. **Login** → Save token otomatis
2. **Form Detail User** → Setup profil 
3. **Form Detail Account** → Setup akun bank
4. **Form Detail Plan** → Setup rencana keuangan
5. **Dashboard endpoints** → Test semua fitur dashboard
6. **Transactions** → Test income/expense

### Daily Usage Flow:
1. **Get Greeting User** → Cek daily budget
2. **Add Income/Expense** → Record transaksi
3. **Get Today Expenses** → Monitor pengeluaran hari ini
4. **Get Daily Saving** → Cek tabungan harian

## 🛠 Environment Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `base_url` | API base URL | `http://localhost:8000` |
| `auth_token` | Bearer token (auto-set after login) | `1\|abc123...` |

## 📌 Notes

- **Auto Token Management:** Login request otomatis save token
- **Authorization Headers:** Semua protected endpoints sudah include `Bearer {{auth_token}}`
- **Sample Data:** Semua request sudah include contoh data yang valid
- **Error Handling:** Collection include proper headers dan response handling

## 🐛 Troubleshooting

### Token Issues:
- Pastikan sudah login dan token tersimpan
- Check environment `auth_token` variable
- Token expire? Login ulang

### Connection Issues:
- Pastikan Laravel server running
- Check `base_url` di environment
- Verify endpoint URLs

### Data Issues:
- Follow setup workflow sequence
- Check required fields di request body
- Validate data types (numbers, dates, etc.)

## 🎉 Ready to Test!

Collection sudah siap digunakan. Start dengan **Login User** untuk mendapatkan token, lalu explore semua endpoints yang tersedia! 

Happy Testing! 🚀