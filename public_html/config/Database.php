<?php
/**
 * public_html/config/Database.php
 *
 * Database wrapper for prepared statements (SQL injection prevention)
 * All queries MUST go through this class
 */

class Database {
    protected $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Execute prepared statement query
     *
     * @param string $query SQL query with ? placeholders
     * @param array $params Parameters to bind
     * @return mysqli_result|bool Query result or false on error
     *
     * @example
     * $db = new Database($conn);
     * $result = $db->query("SELECT * FROM users WHERE nik = ?", ['82086']);
     * $row = $result->fetch_assoc();
     */
    public function query($query, $params = []) {
        $stmt = $this->conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Prepare error: " . $this->conn->error);
        }

        // Bind parameters if provided
        if (!empty($params)) {
            $types = $this->getParamTypes($params);
            $stmt->bind_param($types, ...$params);
        }

        // Execute query
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }

        return $stmt->get_result();
    }

    /**
     * Execute query without returning result (INSERT, UPDATE, DELETE)
     *
     * @param string $query SQL query with ? placeholders
     * @param array $params Parameters to bind
     * @return int Affected rows count, or -1 on error
     *
     * @example
     * $affected = $db->execute("UPDATE users SET name = ? WHERE id = ?", ['John', 1]);
     */
    public function execute($query, $params = []) {
        $stmt = $this->conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Prepare error: " . $this->conn->error);
        }

        if (!empty($params)) {
            $types = $this->getParamTypes($params);
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }

        return $stmt->affected_rows;
    }

    /**
     * Get single row from query
     *
     * @param string $query
     * @param array $params
     * @return array|null Single row as associative array or null if not found
     */
    public function getRow($query, $params = []) {
        $result = $this->query($query, $params);
        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    /**
     * Get all rows from query
     *
     * @param string $query
     * @param array $params
     * @return array Array of rows
     */
    public function getRows($query, $params = []) {
        $result = $this->query($query, $params);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Get single value (scalar)
     *
     * @param string $query
     * @param array $params
     * @return mixed|null
     */
    public function getScalar($query, $params = []) {
        $result = $this->query($query, $params);
        if ($result->num_rows > 0) {
            $row = $result->fetch_row();
            return $row[0];
        }
        return null;
    }

    /**
     * Get last inserted ID
     *
     * @return int
     */
    public function lastInsertId() {
        return $this->conn->insert_id;
    }

    /**
     * Determine parameter types for bind_param
     * i = integer, d = double, s = string, b = blob
     *
     * @param array $params
     * @return string Type string for bind_param
     * @access private
     */
    private function getParamTypes($params) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_string($param)) {
                $types .= 's';
            } elseif (is_null($param)) {
                $types .= 's'; // NULL as string
            } else {
                $types .= 's';
            }
        }
        return $types;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        $this->conn->begin_transaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        $this->conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        $this->conn->rollback();
    }

    /**
     * Get connection object for advanced usage
     *
     * @return mysqli
     */
    public function getConnection() {
        return $this->conn;
    }
}

?>
