-- Criação do banco de dados
CREATE DATABASE IF NOT EXISTS moneyview CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE moneyview;

-- Tabela de usuários
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabela de categorias
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    type ENUM('receita', 'despesa') NOT NULL,
    color VARCHAR(7) DEFAULT '#3B82F6',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_category (user_id, name)
);

-- Tabela principal de contas
CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    type ENUM('receita', 'despesa') NOT NULL,
    status ENUM('pendente', 'paga', 'recebida') DEFAULT 'pendente',
    url VARCHAR(500) NULL,
    is_recurring BOOLEAN DEFAULT FALSE,
    recurring_parent_id INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (recurring_parent_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX idx_user_due_date (user_id, due_date),
    INDEX idx_user_status (user_id, status),
    INDEX idx_user_type (user_id, type)
);

-- Tabela de configurações de recorrência
CREATE TABLE recurring_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    frequency_type ENUM('semanal', 'mensal', 'bimestral', 'trimestral', 'semestral', 'anual', 'personalizado') NOT NULL,
    frequency_interval INT DEFAULT 1, -- Para personalizado: intervalo em dias
    end_date DATE NULL, -- NULL = sem fim
    max_occurrences INT NULL, -- Alternativa ao end_date
    next_generation_date DATE NOT NULL, -- Próxima data para gerar
    last_generated_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_account_recurring (account_id)
);

-- Tabela de projeções de fluxo de caixa (cache)
CREATE TABLE cash_flow_projections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    projection_date DATE NOT NULL,
    projected_income DECIMAL(12,2) DEFAULT 0,
    projected_expenses DECIMAL(12,2) DEFAULT 0,
    projected_balance DECIMAL(12,2) DEFAULT 0,
    accumulated_balance DECIMAL(12,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_date (user_id, projection_date),
    INDEX idx_user_projection_date (user_id, projection_date)
);

-- Inserir categorias padrão (serão criadas para cada usuário no registro)
INSERT INTO categories (user_id, name, type, color) VALUES 
(1, 'Salário', 'receita', '#10B981'),
(1, 'Freelance', 'receita', '#059669'),
(1, 'Investimentos', 'receita', '#047857'),
(1, 'Aluguel', 'despesa', '#EF4444'),
(1, 'Alimentação', 'despesa', '#F97316'),
(1, 'Transporte', 'despesa', '#8B5CF6'),
(1, 'Saúde', 'despesa', '#EC4899'),
(1, 'Educação', 'despesa', '#3B82F6'),
(1, 'Lazer', 'despesa', '#06B6D4'),
(1, 'Telefone/Internet', 'despesa', '#84CC16'),
(1, 'Energia Elétrica', 'despesa', '#F59E0B'),
(1, 'Água', 'despesa', '#0EA5E9');

-- Inserir usuário de teste
INSERT INTO users (name, email, password) VALUES 
('Usuário Teste', 'teste@moneyview.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');