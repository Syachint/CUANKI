# 🏦 SISTEM AUTO-GENERATE DAILY BUDGET

## 📋 **Overview**

Sistem ini secara otomatis membuat record budget baru di tabel `budgets` setiap hari dengan `daily_budget` yang dihitung berdasarkan saldo kebutuhan dibagi jumlah hari dalam bulan.

## 🔄 **Cara Kerja Sistem**

### 🎯 **Auto Budget Generation**

#### **Trigger Points:**
Sistem akan otomatis generate budget harian ketika:
1. `getGreetingUser()` dipanggil
2. `getTodayExpenses()` dipanggil  
3. `getDailySaving()` dipanggil

#### **Logic Flow:**
```php
ensureDailyBudgetExists(userId) {
    if (no_budget_exists_for_today) {
        generateDailyBudget(userId)
    }
}
```

### 📊 **generateDailyBudget Logic**

#### **Formula:**
```
Daily Budget = Saldo Kebutuhan Account / Hari dalam Bulan

Contoh:
- Saldo Kebutuhan Account A: Rp 2,000,000
- Hari dalam Oktober: 31 hari
- Daily Budget Account A = Rp 2,000,000 / 31 = Rp 64,516

- Saldo Kebutuhan Account B: Rp 1,000,000  
- Daily Budget Account B = Rp 1,000,000 / 31 = Rp 32,258

Total Daily Budget = Rp 64,516 + Rp 32,258 = Rp 96,774
```

#### **Data yang Dibuat:**
```php
Budget::create([
    'user_id' => $userId,
    'account_id' => $accountId,
    'daily_budget' => $dailyBudget,    // Kebutuhan Balance / Days in Month
    'daily_saving' => $accumulatedSaving, // Akumulasi sisa dari hari sebelumnya
    'created_at' => today()
]);
```

### 💰 **Daily Saving Logic**

#### **Accumulated Saving:**
```php
// Untuk setiap hari sebelumnya dalam bulan ini:
$dayLeftover = max(0, $daily_budget - $actual_expenses);
$accumulatedDailySaving += $dayLeftover;
```

#### **Contoh:**
```
Day 1 (13 Oct): Budget Rp 96,774 - Expense Rp 50,000 = Leftover Rp 46,774
Day 2 (14 Oct): Budget Rp 96,774 - Expense Rp 75,000 = Leftover Rp 21,774  
Day 3 (15 Oct): Budget Rp 96,774 + Accumulated Saving Rp 68,548

Total Daily Saving = Rp 46,774 + Rp 21,774 = Rp 68,548
```

## 🛠 **Method yang Diubah**

### 1. **getGreetingUser()**
- ✅ **Auto-generate** budget jika belum ada untuk hari ini
- ✅ **Selalu menggunakan** data dari tabel Budget
- ✅ **Real-time data** setelah generate

### 2. **getTodayExpenses()**  
- ✅ **Auto-generate** budget jika belum ada
- ✅ **Enhanced data** dengan remaining budget dan over budget status
- ✅ **Konsisten** dengan data tabel Budget

### 3. **getDailySaving()**
- ✅ **Auto-generate** budget jika belum ada
- ✅ **Akumulasi** daily saving dari hari-hari sebelumnya
- ✅ **Include** daily budget dalam response

## 🆕 **Method Baru**

### 1. **ensureDailyBudgetExists($userId)** - Private
- Cek apakah budget hari ini sudah ada
- Jika belum ada, panggil `generateDailyBudget()`
- Auto-trigger dari method utama

### 2. **generateDailyBudget($userId)** - Private  
- Generate budget untuk semua account user yang punya saldo Kebutuhan
- Hitung daily budget: `kebutuhan_balance / days_in_month`
- Hitung accumulated daily saving dari hari sebelumnya
- Create record di tabel Budget

### 3. **generateTodayBudget()** - Public Endpoint
- **Route:** `POST /api/generate-today-budget`
- **Purpose:** Manual trigger untuk generate budget (testing/debugging)
- **Response:** Detail budget yang di-generate

## 🎯 **Benefits**

### ✅ **Konsistensi Data**
- Semua endpoint menggunakan data dari tabel Budget
- Tidak ada lagi fallback ke perhitungan manual
- Data selalu up-to-date

### ✅ **Otomatis**  
- Budget di-generate otomatis setiap hari
- Tidak perlu manual setup
- Berjalan seamless di background

### ✅ **Akurat**
- Daily budget dihitung berdasarkan saldo terkini
- Daily saving akumulatif dari sisa harian
- Historical data tersimpan untuk tracking

### ✅ **Scalable**
- Support multiple accounts per user
- Each account punya budget terpisah
- Total budget = sum dari semua accounts

## 📈 **Testing**

### **Manual Generate Budget:**
```bash
POST /api/generate-today-budget
Authorization: Bearer {token}
```

### **Response:**
```json
{
    "status": "success",
    "message": "Daily budget generated successfully",
    "data": {
        "date": "2025-10-15",
        "total_daily_budget": 96774,
        "budgets": [
            {
                "account_id": 1,
                "account_name": "BCA Main",
                "daily_budget": 64516,
                "daily_saving": 68548
            },
            {
                "account_id": 2, 
                "account_name": "Mandiri Secondary",
                "daily_budget": 32258,
                "daily_saving": 68548
            }
        ]
    }
}
```

## 🚀 **Ready to Use!**

Sistem sekarang akan otomatis generate budget baru setiap hari. User tinggal menggunakan API seperti biasa, sistem akan handle budget generation di background! 🎉