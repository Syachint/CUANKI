-- PostgreSQL initialization script for CUANKI API
-- This script runs when the container starts for the first time

-- Set timezone to Indonesia
SET timezone = 'Asia/Jakarta';

-- Create extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- Performance optimizations
ALTER SYSTEM SET shared_preload_libraries = 'pg_stat_statements';
ALTER SYSTEM SET pg_stat_statements.track = 'all';
ALTER SYSTEM SET log_statement = 'none';
ALTER SYSTEM SET log_min_duration_statement = 1000;

-- Memory settings (adjust based on your server RAM)
ALTER SYSTEM SET shared_buffers = '256MB';
ALTER SYSTEM SET effective_cache_size = '1GB';
ALTER SYSTEM SET maintenance_work_mem = '64MB';
ALTER SYSTEM SET work_mem = '4MB';

-- Connection settings
ALTER SYSTEM SET max_connections = 100;
ALTER SYSTEM SET default_statistics_target = 100;

-- WAL settings for better performance
ALTER SYSTEM SET wal_buffers = '16MB';
ALTER SYSTEM SET checkpoint_completion_target = 0.9;
ALTER SYSTEM SET checkpoint_timeout = '10min';

-- Query optimization
ALTER SYSTEM SET random_page_cost = 1.1;
ALTER SYSTEM SET effective_io_concurrency = 200;

-- Create database user if not exists (backup in case Docker env vars fail)
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'cuanki_user') THEN
        CREATE ROLE cuanki_user WITH LOGIN PASSWORD 'cuanki_password';
    END IF;
END
$$;

-- Grant privileges to user
GRANT ALL PRIVILEGES ON DATABASE cuanki TO cuanki_user;
GRANT ALL ON SCHEMA public TO cuanki_user;

-- Create indexes for common Laravel patterns (will be created after migrations)
-- These are examples - actual indexes will depend on your queries

-- Function to create indexes after tables exist
CREATE OR REPLACE FUNCTION create_laravel_indexes() RETURNS void AS $$
BEGIN
    -- Check if users table exists and create indexes
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'users') THEN
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_email ON users(email);
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_created_at ON users(created_at);
    END IF;

    -- Check if personal_access_tokens table exists
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'personal_access_tokens') THEN
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_personal_access_tokens_tokenable ON personal_access_tokens(tokenable_type, tokenable_id);
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_personal_access_tokens_token ON personal_access_tokens(token);
    END IF;

    -- Indexes for your custom tables
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'accounts') THEN
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_accounts_user_id ON accounts(user_id);
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_accounts_bank_id ON accounts(bank_id);
    END IF;

    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'expenses') THEN
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_expenses_user_id ON expenses(user_id);
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_expenses_date ON expenses(date);
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_expenses_category_id ON expenses(category_id);
    END IF;

    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'incomes') THEN
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_incomes_user_id ON incomes(user_id);
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_incomes_date ON incomes(date);
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Log successful initialization
INSERT INTO pg_stat_statements_info (dealloc) VALUES (0) ON CONFLICT DO NOTHING;

-- Set locale
UPDATE pg_database SET datcollate = 'en_US.UTF-8', datctype = 'en_US.UTF-8' WHERE datname = current_database();

-- Create a health check function
CREATE OR REPLACE FUNCTION health_check() RETURNS text AS $$
BEGIN
    RETURN 'PostgreSQL is healthy - ' || now()::text;
END;
$$ LANGUAGE plpgsql;

-- Optimize for Laravel's specific query patterns
-- Set configuration for better JSON handling (if you use JSON columns)
ALTER SYSTEM SET gin_pending_list_limit = '4MB';

COMMIT;
