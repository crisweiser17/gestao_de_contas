-- Adicionar campo attachment na tabela accounts
-- Execute este script para adicionar o campo de anexo/comprovante

USE moneyview;

ALTER TABLE accounts 
ADD COLUMN attachment VARCHAR(255) NULL 
AFTER notes;

-- Comentário: Campo para armazenar o caminho do arquivo de comprovante
-- Tipos suportados: PDF, JPG, JPEG, PNG