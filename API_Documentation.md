# CUANKI API Documentation

## Base URL
```
http://localhost:8000/api
```

## Authentication
Most endpoints require authentication using Laravel Sanctum. Include the bearer token in the Authorization header:
```
Authorization: Bearer {token}
```

---

## Authentication Endpoints

### 1. Register User
**POST** `/register`

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    },
    "token": "1|abc123..."
  }
}
```

### 2. Login User
**POST** `/login`

**Request Body:**
```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    },
    "token": "2|def456..."
  }
}
```

### 3. Refresh Token
**POST** `/refresh`

**Headers:**
```
Authorization: Bearer {current_token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Token refreshed successfully",
  "data": {
    "token": "3|ghi789..."
  }
}
```

### 4. Logout
**POST** `/logout`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Logged out successfully"
}
```

---

## Form Detail Endpoints

### 5. Get Banks List
**GET** `/listbank`

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "code_name": "BCA",
      "bank_name": "Bank Central Asia"
    },
    {
      "id": 2,
      "code_name": "MANDIRI",
      "bank_name": "Bank Mandiri"
    }
  ]
}
```

### 6. Get Origins
**GET** `/origins`

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "name": "Gaji"
    },
    {
      "id": 2,
      "name": "Uang Saku"
    }
  ]
}
```

### 7. Form Detail User
**POST** `/form/user`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "username": "johndoe",
  "age": 25,
  "phone": "08123456789"
}
```

### 8. Form Detail Account
**POST** `/form/account`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "bank_id": 1,
  "initial_balance": 1000000
}
```

### 9. Form Detail Plan
**POST** `/form/plan`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "monthly_income": 5000000,
  "income_date": 25,
  "saving_target_amount": 1000000,
  "saving_target_duration": 5,
  "emergency_target_amount": 10000000
}
```

---

## Transaction Endpoints

### 10. Get User Accounts
**GET** `/user-accounts`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "accounts": [
      {
        "value": "BCA - Kebutuhan",
        "label": "BCA - Kebutuhan",
        "account_id": 1,
        "account_name": "BCA",
        "type": "Kebutuhan",
        "balance": 1500000,
        "formatted_balance": "Rp 1.500.000"
      },
      {
        "value": "BCA - Tabungan",
        "label": "BCA - Tabungan",
        "account_id": 1,
        "account_name": "BCA",
        "type": "Tabungan",
        "balance": 5000000,
        "formatted_balance": "Rp 5.000.000"
      }
    ],
    "total_options": 2
  }
}
```

### 11. Get Expense Categories
**GET** `/expense-categories`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "categories": [
      {
        "value": 1,
        "label": "Makanan",
        "category_id": 1,
        "category_name": "Makanan"
      },
      {
        "value": 2,
        "label": "Transport",
        "category_id": 2,
        "category_name": "Transport"
      }
    ],
    "total_categories": 2
  }
}
```

### 12. Add Income
**POST** `/add-income`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "tanggal": "2025-10-13",
  "total": 50000,
  "notes": "Bonus kerja",
  "aset": "BCA - Kebutuhan"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Income added successfully",
  "data": {
    "income_id": 1,
    "user_id": 1,
    "account_id": 1,
    "account_name": "BCA",
    "amount": 50000,
    "note": "Bonus kerja",
    "received_date": "2025-10-13",
    "confirmation_status": "Pending",
    "allocation_update": {
      "allocation_type": "Kebutuhan",
      "old_balance": 1500000,
      "new_balance": 1550000,
      "balance_increase": 50000
    },
    "account_update": {
      "old_current_balance": 6500000,
      "new_current_balance": 6550000,
      "balance_increase": 50000
    },
    "budget_update": {
      "budget_id": 1,
      "action": "updated",
      "old_daily_budget": 48387,
      "new_daily_budget": 50000,
      "daily_budget_increase": 1613,
      "daily_saving": 0,
      "kebutuhan_balance": 1550000,
      "days_in_month": 31,
      "formatted": {
        "old_daily_budget": "Rp 48.387",
        "new_daily_budget": "Rp 50.000",
        "daily_budget_increase": "Rp 1.613"
      }
    },
    "formatted": {
      "amount": "Rp 50.000",
      "received_date": "13 Okt 2025",
      "aset": "BCA - Kebutuhan"
    }
  }
}
```

### 13. Add Expense
**POST** `/add-expense`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "tanggal": "2025-10-13",
  "total": 25000,
  "notes": "Makan siang",
  "kategori": 1,
  "aset": "BCA - Kebutuhan"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Expense added successfully",
  "data": {
    "expense_id": 1,
    "user_id": 1,
    "account_id": 1,
    "account_name": "BCA",
    "category_id": 1,
    "category_name": "Makanan",
    "amount": 25000,
    "note": "Makan siang",
    "expense_date": "2025-10-13",
    "allocation_update": {
      "allocation_type": "Kebutuhan",
      "old_balance": 1550000,
      "new_balance": 1525000,
      "balance_decrease": 25000
    },
    "account_update": {
      "old_current_balance": 6550000,
      "new_current_balance": 6525000,
      "balance_decrease": 25000
    },
    "budget_update": {
      "budget_id": 1,
      "action": "updated_after_expense",
      "old_daily_budget": 50000,
      "new_daily_budget": 25000,
      "daily_budget_decrease": 25000,
      "expense_amount": 25000,
      "daily_saving": 0,
      "kebutuhan_balance": 1525000,
      "formatted": {
        "old_daily_budget": "Rp 50.000",
        "new_daily_budget": "Rp 25.000",
        "daily_budget_decrease": "Rp 25.000",
        "expense_amount": "Rp 25.000"
      }
    },
    "formatted": {
      "amount": "Rp 25.000",
      "expense_date": "13 Okt 2025",
      "aset": "BCA - Kebutuhan",
      "kategori": "Makanan"
    }
  }
}
```

---

## Dashboard Endpoints

### 14. Get Greeting User
**GET** `/greeting-user`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Hai, johndoe! ini uang kamu hari ini Rp 49.193",
  "data": {
    "user": {
      "name": "John Doe",
      "username": "johndoe"
    },
    "daily_budget": {
      "amount": 49193,
      "formatted": "Rp 49.193",
      "kebutuhan_balance": 1525000,
      "days_in_month": 31
    }
  }
}
```

### 15. Get Goals Progress
**GET** `/goals-progress`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "main_saving_target": {
      "amount": 60000000,
      "current_amount": 5000000,
      "progress_percentage": 8.33,
      "formatted_amount": "Rp 60.000.000",
      "formatted_current": "Rp 5.000.000"
    },
    "finance_plan": {
      "monthly_income": 5000000,
      "income_date": 25,
      "monthly_saving_target": 1000000,
      "saving_target_duration_years": 5,
      "saving_target_duration_months": 60,
      "total_saving_target": 60000000,
      "emergency_target_amount": 10000000,
      "formatted": {
        "monthly_income": "Rp 5.000.000",
        "monthly_saving_target": "Rp 1.000.000",
        "saving_target_duration": "5 tahun (60 bulan)",
        "total_saving_target": "Rp 60.000.000",
        "emergency_target_amount": "Rp 10.000.000"
      }
    },
    "goals": [
      {
        "type": "saving",
        "goal_name": "Target Tabungan Utama",
        "target_amount": 60000000,
        "current_amount": 5000000,
        "remaining_amount": 55000000,
        "progress_percentage": 8.33,
        "target_duration_years": 5,
        "target_duration_months": 60,
        "target_date": "2030-10-13",
        "days_remaining": 1826,
        "monthly_saving_needed": 916667,
        "is_completed": false,
        "formatted": {
          "target_amount": "Rp 60.000.000",
          "current_amount": "Rp 5.000.000",
          "remaining_amount": "Rp 55.000.000",
          "progress_percentage": "8.33%",
          "monthly_saving_needed": "Rp 916.667",
          "target_date": "13 Okt 2030",
          "days_remaining": "1826 hari lagi"
        }
      }
    ]
  }
}
```

### 16. Get Today Expenses
**GET** `/today-expenses`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "daily_budget": 25000,
    "today_expenses": 0,
    "budget_records_count": 1,
    "formatted": {
      "daily_budget": "Rp 25.000",
      "today_expenses": "Rp 0"
    }
  }
}
```

### 17. Get Daily Saving
**GET** `/daily-saving`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "daily_saving": 0,
    "budget_records_count": 1,
    "formatted": {
      "daily_saving": "Rp 0"
    }
  }
}
```

### 18. Update Account Balance
**PUT** `/update-account-balance`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
  "account_id": 1,
  "type": "Kebutuhan",
  "balance_per_type": 2000000
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Account balance updated successfully",
  "data": {
    "account_id": 1,
    "account_name": "BCA",
    "type": "Kebutuhan",
    "total_banks": 1,
    "allocation_update": {
      "old_balance_per_type": 1525000,
      "new_balance_per_type": 2000000,
      "balance_change": 475000
    },
    "account_balance": {
      "old_current_balance": 6525000,
      "new_current_balance": 7000000,
      "current_balance_change": 475000
    },
    "calculation_method": "Single bank: kebutuhan + tabungan",
    "budget_tracking": {
      "budget_id": 1,
      "daily_budget": 64516,
      "daily_saving": 0,
      "kebutuhan_balance": 2000000,
      "days_in_month": 31,
      "calculation": "kebutuhan_balance (2000000) / days_in_month (31)",
      "is_new_record": false,
      "formatted": {
        "daily_budget": "Rp 64.516",
        "daily_saving": "Rp 0",
        "kebutuhan_balance": "Rp 2.000.000"
      }
    },
    "formatted": {
      "type": "Kebutuhan",
      "old_balance_per_type": "Rp 1.525.000",
      "new_balance_per_type": "Rp 2.000.000",
      "new_current_balance": "Rp 7.000.000"
    }
  }
}
```

---

## Error Responses

### Validation Error (422)
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field is required."]
  }
}
```

### Authentication Error (401)
```json
{
  "status": "error",
  "message": "User not authenticated"
}
```

### Not Found Error (404)
```json
{
  "status": "error",
  "message": "Account not found: BCA"
}
```

### Server Error (500)
```json
{
  "status": "error",
  "message": "Error adding income: Database connection failed"
}
```

---

## Postman Environment Variables

Create these variables in your Postman environment:

- `base_url`: `http://localhost:8000/api`
- `auth_token`: `{your_bearer_token}` (set after login)

## Testing Flow

1. **Register/Login** → Get auth token
2. **Set up forms** → Complete user, account, and plan forms
3. **Test transactions** → Add income and expenses
4. **Check dashboard** → View greeting, goals, budgets
5. **Update balances** → Test balance updates

## Notes

- All monetary values are in Indonesian Rupiah (IDR)
- Dates should be in YYYY-MM-DD format
- Authentication token expires and may need refresh
- Account format for aset field: "BankName - AllocationType"
- Available allocation types: Kebutuhan, Tabungan, Darurat