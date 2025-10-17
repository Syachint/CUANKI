# Daily Budget Usage Bar API

## Endpoint: GET /api/usage-bar

**Description:** Endpoint untuk mendapatkan data usage bar harian budget seperti pada gambar yang ditunjukkan user.

### Headers:
```
Authorization: Bearer {token}
Content-Type: application/json
```

### Request Parameters:
```json
{
    "user_id": 1
}
```

### Response Format:

#### Success Response (200):
```json
{
    "success": true,
    "data": {
        "today_spending": "60.000",
        "daily_limit": "70.000", 
        "percentage": 85.7,
        "remaining": "10.000",
        "status": "warning",
        "formatted_text": "Transaksi anda hari ini: Rp 60.000/Rp 70.000"
    }
}
```

#### Response Explanation:
- `today_spending`: Total pengeluaran hari ini (formatted dengan titik pemisah ribuan)
- `daily_limit`: Limit budget harian dari Budget model atau AccountAllocation
- `percentage`: Persentase penggunaan (0-100%)
- `remaining`: Sisa budget yang tersisa
- `status`: Status penggunaan budget:
  - `safe`: < 80% (hijau)
  - `warning`: 80-99% (kuning/orange) 
  - `over_budget`: >= 100% (merah)
- `formatted_text`: Text siap pakai untuk ditampilkan di UI

### Logic Flow:

1. **Get Daily Budget:**
   - Cari budget dengan frequency='daily' yang masih aktif
   - Jika tidak ada, hitung dari AccountAllocation (total/30 hari)

2. **Calculate Today's Expenses:**
   - Sum semua expense user untuk hari ini (tanggal sekarang)

3. **Calculate Usage:**
   - Percentage = (today_expenses / daily_limit) * 100
   - Status berdasarkan percentage
   - Remaining = daily_limit - today_expenses

### Example Usage in Frontend:

```javascript
// Fetch usage bar data
const response = await fetch('/api/usage-bar', {
    method: 'GET',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        user_id: currentUserId
    })
});

const data = await response.json();

// Update UI
document.getElementById('usage-text').textContent = data.data.formatted_text;
document.getElementById('progress-bar').style.width = data.data.percentage + '%';

// Set color based on status
const progressBar = document.getElementById('progress-bar');
switch(data.data.status) {
    case 'safe':
        progressBar.className = 'progress-bar bg-success';
        break;
    case 'warning':
        progressBar.className = 'progress-bar bg-warning';
        break;
    case 'over_budget':
        progressBar.className = 'progress-bar bg-danger';
        break;
}
```

### Test Cases:

#### Test 1: Normal Usage (Safe)
- Daily Budget: Rp 100.000
- Today Spending: Rp 45.000
- Expected: 45%, status="safe"

#### Test 2: High Usage (Warning)  
- Daily Budget: Rp 70.000
- Today Spending: Rp 60.000
- Expected: 85.7%, status="warning"

#### Test 3: Over Budget
- Daily Budget: Rp 50.000
- Today Spending: Rp 65.000
- Expected: 100%, status="over_budget"

### Notes:
- Menggunakan timezone Asia/Jakarta untuk tanggal hari ini
- Format angka menggunakan pemisah titik untuk ribuan
- Budget diambil dari model Budget (frequency=daily) atau fallback ke AccountAllocation
- Response sudah siap untuk digunakan langsung di progress bar UI