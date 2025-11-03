<?php
/**
 * CLASSE DE CONEXÃO COM BANCO DE DADOS
 * Usa as configurações definidas no config.php baseadas no ambiente
 */
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        // Usa as constantes definidas no config.php
        $this->host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
    }

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
            
            // Log de conexão para debug (apenas em desenvolvimento)
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("✅ Conexão com banco estabelecida: " . $this->username . "@" . $this->host . "/" . $this->db_name);
            }
            
        } catch(PDOException $exception) {
            $error_msg = "Erro de conexão: " . $exception->getMessage();
            
            // Log detalhado apenas em desenvolvimento
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("❌ Erro de conexão com banco: " . $error_msg);
            }
            
            // Em produção, mostra erro genérico
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
                die("Erro de conexão: Erro interno do servidor");
            } else {
                die($error_msg);
            }
        }
        
        return $this->conn;
    }
}
?>