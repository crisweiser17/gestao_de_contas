<?php
// Configurações de banco de dados para DESENVOLVIMENTO LOCAL
class Database {
    private $host = 'localhost';
    private $db_name = 'moneyview';
    private $username = 'root';
    private $password = '';  // Senha vazia para MySQL local
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
            echo "Erro de conexão: " . $exception->getMessage();
            die();
        }
        
        return $this->conn;
    }
}
?>