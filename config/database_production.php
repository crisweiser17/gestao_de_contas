<?php
// Configurações de banco de dados para PRODUÇÃO
// IMPORTANTE: Ajuste as configurações abaixo para seu hosting

class Database {
    // ALTERE ESTAS CONFIGURAÇÕES PARA SEU HOSTING
    private $host = 'localhost';           // Host do banco (ex: localhost, mysql.seuhost.com)
    private $db_name = 'moneyview';        // Nome do banco de dados
    private $username = 'seu_usuario';     // Usuário do banco
    private $password = 'sua_senha';       // Senha do banco
    private $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                ]
            );
        } catch(PDOException $exception) {
            // Em produção, não exibir detalhes do erro
            error_log("Erro de conexão com banco: " . $exception->getMessage());
            die("Erro interno do servidor. Tente novamente mais tarde.");
        }
        
        return $this->conn;
    }
}
?>