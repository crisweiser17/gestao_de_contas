-- Migration: add 'name' column to accounts to store person/company associated
-- Safe to run multiple times on MySQL 8+ using IF NOT EXISTS
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS `name` VARCHAR(255) NULL AFTER `description`;

-- Optional: index to speed up filtering/search by name
ALTER TABLE accounts
  ADD INDEX IF NOT EXISTS idx_accounts_name (`name`);