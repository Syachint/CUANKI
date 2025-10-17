# Test Account Allocation Issue

## 🔍 **Step-by-Step Debugging**

### **1. Test Available Allocations First:**

```bash
curl -X GET "http://localhost:8000/api/goals/available-allocations" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

**Expected Response:**
```json
{
  "status": "success",
  "message": "Available allocations retrieved successfully",
  "data": {
    "allocations": [
      {
        "allocation_id": 1,
        "account_id": 1,
        "account_name": "BCA Utama",
        "bank_name": "BCA",
        "type": "Tabungan",
        "current_balance": 500000,
        "formatted_balance": "Rp 500.000",
        "last_updated": "2025-10-17 12:00:00"
      }
    ],
    "debug": {
      "user_id": 1,
      "total_accounts": 1,
      "account_ids": [1],
      "total_allocations": 3,
      "tabungan_allocations": 1,
      "all_allocation_types": ["Tabungan", "Kebutuhan", "Darurat"],
      "allocation_summary": {
        "Tabungan": {"count": 1, "ids": [1]},
        "Kebutuhan": {"count": 1, "ids": [2]},
        "Darurat": {"count": 1, "ids": [3]}
      }
    }
  }
}
```

### **2. Use Valid allocation_id from Step 1:**

```bash
curl -X POST "http://localhost:8000/api/goals" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "goal_name": "Emergency Fund Target",
    "target_amount": 10000000,
    "target_deadline": "2025-12-31",
    "account_allocation_id": 1
  }'
```

### **3. Common Error Scenarios:**

#### **Scenario A: No Tabungan Allocation**
Response:
```json
{
  "data": {
    "allocations": [],
    "debug": {
      "tabungan_allocations": 0,
      "all_allocation_types": ["Kebutuhan", "Darurat"]
    }
  }
}
```
**Solution:** User needs to create a "Tabungan" type allocation first.

#### **Scenario B: Using Wrong allocation_id**
Request with `account_allocation_id: 999`:
```json
{
  "status": "error",
  "message": "Account allocation not found",
  "debug": {
    "allocation_id": 999,
    "available_allocations": [1, 2, 3],
    "user_id": 1
  }
}
```

#### **Scenario C: Using allocation_id from different user**
Request with `account_allocation_id: 5` (belongs to user 3):
```json
{
  "status": "error",
  "message": "Access denied - account belongs to different user",
  "debug": {
    "allocation_id": 5,
    "account_user_id": 3,
    "requested_user_id": 1,
    "allocation_type": "Tabungan"
  }
}
```

#### **Scenario D: Using non-Tabungan allocation**
Request with Darurat allocation ID:
```json
{
  "status": "error",
  "message": "Goals can only be created for \"Tabungan\" type allocations"
}
```

### **4. Check UserSeeder Data:**

From UserSeeder, expected allocation IDs:

**User Itsar (ID: 1):**
- Account 1 → Allocation 1 (Tabungan), 2 (Kebutuhan), 3 (Darurat)

**User Santa (ID: 2):**  
- Account 2 → Allocation 4 (Tabungan), 5 (Kebutuhan), 6 (Darurat)
- Account 3 → Allocation 7 (Tabungan), 8 (Kebutuhan), 9 (Darurat)

**User Ian (ID: 3):**
- Account 4 → Allocation 10 (Tabungan), 11 (Kebutuhan), 12 (Darurat)
- Account 5 → Allocation 13 (Tabungan), 14 (Kebutuhan), 15 (Darurat)  
- Account 6 → Allocation 16 (Tabungan), 17 (Kebutuhan), 18 (Darurat)

### **5. Correct Test Cases:**

**For User Itsar (ID: 1):**
```json
{
  "goal_name": "Vacation Fund",
  "target_amount": 5000000,
  "account_allocation_id": 1
}
```

**For User Santa (ID: 2):**
```json
{
  "goal_name": "House Down Payment", 
  "target_amount": 50000000,
  "account_allocation_id": 4
}
```

**For User Ian (ID: 3):**
```json
{
  "goal_name": "Education Fund",
  "target_amount": 20000000, 
  "account_allocation_id": 10
}
```

### **Quick Fix Commands:**

#### **Check database directly:**
```sql
-- Check user's accounts
SELECT id, user_id, account_name FROM accounts WHERE user_id = 1;

-- Check user's allocations
SELECT aa.id, aa.type, aa.account_id, a.user_id 
FROM accounts_allocation aa 
JOIN accounts a ON aa.account_id = a.id 
WHERE a.user_id = 1;

-- Check Tabungan allocations for user
SELECT aa.id, aa.type, aa.balance_per_type, a.account_name 
FROM accounts_allocation aa 
JOIN accounts a ON aa.account_id = a.id 
WHERE a.user_id = 1 AND aa.type = 'Tabungan';
```

### **Resolution Steps:**

1. **Call `/api/goals/available-allocations`** first
2. **Use allocation_id from the response** 
3. **Make sure it's type "Tabungan"**
4. **Verify user owns the account**

**Most likely issue:** Using wrong `account_allocation_id` in the request! 🎯