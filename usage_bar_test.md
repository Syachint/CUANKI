# Test Usage Bar API

## Test menggunakan data UserSeeder yang sudah ada:

### 1. Test dengan User Itsar (ID: 1)
```bash
curl -X GET "http://localhost:8000/api/usage-bar" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

### Expected Behavior:

1. **Ambil Budget User:**
   - Cari budget dari tabel `budgets` dengan `user_id = 1`
   - Gunakan field `initial_daily_budget` sebagai daily limit
   - Jika tidak ada, fallback ke AccountAllocation / 30 hari

2. **Hitung Today's Expenses:**
   - Sum semua expense user ID 1 untuk tanggal hari ini (2025-10-17)
   - Dari UserSeeder, user Itsar punya banyak expense records

3. **Calculate Usage:**
   - Percentage = (today_expenses / daily_limit) * 100
   - Status: safe (<80%), warning (80-99%), over_budget (≥100%)

### Data dari UserSeeder:

**User Itsar (ID: 1):**
- Punya 1 account dengan spending_limit
- Punya banyak expense records (dari Sep 16 - Oct 16)
- Budget record dengan initial_daily_budget

### Sample Response:
```json
{
  "success": true,
  "data": {
    "today_spending": "0",
    "daily_limit": "50.000",
    "percentage": 0.0,
    "remaining": "50.000", 
    "status": "safe",
    "formatted_text": "Transaksi anda hari ini: Rp 0/Rp 50.000"
  }
}
```

*Note: Today spending bisa jadi 0 karena expense records di seeder sampai Oct 16, sedangkan hari ini Oct 17*

### Test Cases:

1. **User dengan Budget Record:**
   - User 1, 2, 3 sudah punya budget records
   - Should use `initial_daily_budget` from budgets table

2. **User tanpa Budget:**
   - Jika user tidak punya budget record
   - Should fallback ke AccountAllocation spending_limit / 30

3. **Edge Cases:**
   - User tanpa AccountAllocation → default 100k daily limit
   - User dengan spending > daily limit → percentage capped at 100%
   - Invalid user → 404 error

### Fixed Issues:

✅ **Removed non-existent columns:**
- `start_date` dan `end_date` tidak ada di tabel budgets
- Fixed query untuk hanya cari `daily_budget` yang not null

✅ **Fixed variable references:**
- Ganti `$request->user_id` dengan `$userId` 
- Proper user authentication dengan `$request->user()`

✅ **Fixed logic errors:**
- Proper fallback mechanism untuk daily limit
- Correct calculation untuk percentage dan status
- Added default 100k limit jika tidak ada data

✅ **Exception handling:**
- Proper `\Exception` namespace
- Comprehensive error messages