<?php
$db_host = "localhost";
$db_name = "rfnhscco_routine";
$db_user = "root";
$db_pass = 'a';

class Database {
    private $host = "localhost";
    private $db_name = "rfnhscco_routine";
    private $username = "rfnhscco_routine";
    private $password = 'abO(sOQ}AhmZ$yd4';
    private $conn;

    public function connect() {
        $this->conn = new mysqli(
            $this->host,
            $this->username,
            $this->password,
            $this->db_name
        );

        if ($this->conn->connect_error) {
            die("Database connection failed: " . $this->conn->connect_error);
        }

        // Set character set
        $this->conn->set_charset("utf8mb4");

        return $this->conn;
    }

    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

function getDBConnection() {
    global $db_host, $db_user, $db_pass, $db_name;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (mysqli_sql_exception $e) {
        die("Database connection error: " . $e->getMessage());
    }
}

// $db = new Database();
// $conn = $db->connect();
?>