# 🚀 Instruções para Deploy no Hosting Online

## 📋 Checklist de Deploy

### 1. 📁 Arquivos para Upload
Faça upload de todos os arquivos EXCETO:
- `config/config.php` (usar `config_production.php`)
- `config/database.php` (usar `database_production.php`)
- `database/` (apenas o arquivo SQL de backup)
- `test_*.php`

### 2. 🗄️ Configuração do Banco de Dados

#### Criar o banco:
1. Acesse o painel do seu hosting (cPanel, Plesk, etc.)
2. Crie um novo banco MySQL chamado `moneyview`
3. Crie um usuário para o banco com todas as permissões
4. Anote: host, nome do banco, usuário e senha

#### Importar dados:
1. Use phpMyAdmin ou ferramenta similar
2. Importe o arquivo: `database/moneyview_backup_20251021_120519.sql`
3. Verifique se todas as tabelas foram criadas

### 3. ⚙️ Configuração dos Arquivos

#### Renomear arquivos de configuração:
```bash
# No servidor, renomeie:
config_production.php → config.php
database_production.php → database.php
```

#### Editar `config/database.php`:
```php
private $host = 'SEU_HOST_MYSQL';        // Ex: localhost ou mysql.seuhost.com
private $db_name = 'SEU_NOME_BANCO';     // Nome do banco criado
private $username = 'SEU_USUARIO';       // Usuário do banco
private $password = 'SUA_SENHA';         // Senha do banco
```

#### Editar `config/config.php`:
```php
define('BASE_URL', 'https://seudominio.com/'); // Seu domínio real
```

### 4. 📂 Permissões de Pastas
Configure as permissões:
```bash
chmod 755 uploads/
chmod 755 uploads/attachments/
chmod 644 uploads/.htaccess
```

### 5. 🔐 Seus Dados de Login
**Email:** hello@crisweiser.com  
**Senha:** Ccmcw17@  
**Nome:** Cris Weiser

### 6. 📊 Dados Incluídos no Backup
✅ Usuário atualizado com novas credenciais  
✅ Todas as 13 transações/contas  
✅ Todas as categorias personalizadas  
✅ Configurações de recorrência  
✅ Anexos (arquivos em uploads/attachments/)

### 7. 🛡️ Segurança em Produção
- ✅ Erros não são exibidos na tela
- ✅ Logs de erro configurados
- ✅ Sessões seguras (HTTPS)
- ✅ Cookies seguros habilitados

### 8. 📝 Logs de Erro
Crie a pasta para logs:
```bash
mkdir logs
chmod 755 logs
```

### 9. ✅ Teste Final
1. Acesse seu domínio
2. Faça login com: hello@crisweiser.com / Ccmcw17@
3. Verifique se todos os dados estão presentes
4. Teste upload de anexos
5. Teste criação de nova conta
6. Teste página de perfil

## 🆘 Problemas Comuns

### Erro de conexão com banco:
- Verifique host, usuário, senha e nome do banco
- Confirme se o usuário tem permissões no banco
- Verifique se o MySQL está ativo

### Erro 500:
- Verifique logs de erro do servidor
- Confirme permissões das pastas
- Verifique se PHP 7.4+ está ativo

### Anexos não funcionam:
- Verifique permissões da pasta uploads/
- Confirme se o .htaccess está presente
- Teste upload de arquivo pequeno

## 📞 Suporte
Se precisar de ajuda, tenho todos os detalhes do seu sistema e posso auxiliar com qualquer problema no deploy!