-- Migração para adicionar campo last_login na tabela users
-- Execute este script no banco de dados para adicionar o campo last_login

USE moneyview;

-- Adicionar campo last_login na tabela users
ALTER TABLE users 
ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL 
AFTER updated_at;

-- Comentário: O campo last_login será atualizado sempre que o usuário fizer login
-- Valor NULL indica que o usuário nunca fez login