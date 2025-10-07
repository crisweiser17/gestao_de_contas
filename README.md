# MoneyView - Sistema de Gestão Financeira

Sistema completo de gestão financeira pessoal desenvolvido em PHP com MySQL, oferecendo controle de contas a pagar/receber, categorização, recorrências automáticas e projeções de fluxo de caixa.

## 🚀 Funcionalidades

### ✅ Gestão de Contas
- Cadastro de contas a pagar e receber
- Categorização personalizada
- Status de pagamento (pendente, paga, vencida)
- Campos completos: descrição, valor, vencimento, URL, observações

### 📊 Relatórios e Análises
- Dashboard com resumo financeiro
- Relatórios detalhados por categoria e período
- Gráficos interativos (Chart.js)
- Exportação em PDF e CSV

### 💰 Fluxo de Caixa
- Projeções para 30, 60, 90, 180 dias e 1 ano
- Visualização de saldo futuro
- Resumo semanal e diário
- Gráfico de evolução do saldo

### 🔄 Sistema de Recorrências
- Configuração de contas recorrentes
- Geração automática via cron job
- Frequências: diária, semanal, mensal, anual
- Controle de data limite e máximo de ocorrências

### 🎨 Interface
- Design responsivo com Tailwind CSS
- Interface intuitiva e moderna
- Filtros avançados
- Navegação simplificada

## 📋 Requisitos

- PHP 7.4+
- MySQL 5.7+
- Servidor web (Apache/Nginx)
- Extensões PHP: PDO, PDO_MySQL

## 🛠️ Instalação

1. **Clone ou baixe o projeto**
   ```bash
   git clone [url-do-repositorio]
   cd moneyview
   ```

2. **Configure o banco de dados**
   - Edite `config/database.php` com suas credenciais MySQL
   - Acesse `install.php` no navegador para criar as tabelas

3. **Configure o cron job (opcional)**
   ```bash
   # Adicione ao crontab para processar recorrências diariamente às 6h
   0 6 * * * /usr/bin/php /caminho/para/moneyview/cron/process_recurring.php
   ```

4. **Acesse o sistema**
   - Registre-se em `register.php`
   - Faça login em `login.php`

## 📁 Estrutura do Projeto

```
moneyview/
├── config/
│   └── database.php          # Configuração do banco
├── models/
│   ├── Account.php           # Modelo de contas
│   ├── Category.php          # Modelo de categorias
│   ├── RecurringSetting.php  # Modelo de recorrências
│   └── User.php              # Modelo de usuários
├── cron/
│   └── process_recurring.php # Script de processamento automático
├── schema.sql                # Estrutura do banco de dados
├── install.php               # Instalador do sistema
├── index.php                 # Dashboard principal
├── login.php                 # Página de login
├── register.php              # Página de registro
├── accounts.php              # Gestão de contas
├── categories.php            # Gestão de categorias
├── recurring.php             # Gestão de recorrências
├── cash-flow.php             # Projeção de fluxo de caixa
├── reports.php               # Relatórios e análises
└── logout.php                # Logout
```

## 🎯 Como Usar

### 1. Primeiro Acesso
- Registre-se no sistema
- Categorias padrão serão criadas automaticamente

### 2. Cadastro de Contas
- Acesse "Contas" no menu
- Preencha os dados da conta
- Configure recorrência se necessário

### 3. Acompanhamento
- Dashboard mostra resumo geral
- "Fluxo de Caixa" exibe projeções futuras
- "Relatórios" oferece análises detalhadas

### 4. Recorrências
- Configure contas que se repetem
- Sistema gera automaticamente via cron
- Gerencie em "Recorrências"

## 🔧 Configurações Avançadas

### Banco de Dados
Edite `config/database.php`:
```php
$host = 'localhost';
$dbname = 'moneyview';
$username = 'seu_usuario';
$password = 'sua_senha';
```

### Cron Job
Para processamento automático de recorrências:
```bash
# Diário às 6h
0 6 * * * /usr/bin/php /caminho/completo/cron/process_recurring.php

# A cada hora
0 * * * * /usr/bin/php /caminho/completo/cron/process_recurring.php
```

## 📊 Recursos Técnicos

- **Backend**: PHP com PDO para segurança
- **Frontend**: HTML5, CSS3, JavaScript, Tailwind CSS
- **Banco**: MySQL com relacionamentos otimizados
- **Gráficos**: Chart.js para visualizações
- **Exportação**: TCPDF para PDF, CSV nativo
- **Segurança**: Sessões PHP, prepared statements

## 🚀 Próximas Melhorias

- [ ] API REST para integração
- [ ] App mobile
- [ ] Backup automático
- [ ] Notificações por email
- [ ] Múltiplas moedas
- [ ] Importação de extratos bancários

## 📝 Licença

Este projeto é de uso livre para fins educacionais e pessoais.

## 🤝 Contribuição

Contribuições são bem-vindas! Sinta-se à vontade para:
- Reportar bugs
- Sugerir melhorias
- Enviar pull requests

---

**MoneyView** - Sua gestão financeira simplificada! 💰📊