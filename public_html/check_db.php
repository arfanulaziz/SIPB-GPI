<?php
/**
 * check_db.php
 * Check database tables - SIMPLE VERSION without hang
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 DATABASE STRUCTURE CHECK</h2>";

// Load config
require_once 'config/config.php';

echo "✅ Connected to database: " . getenv('DB_NAME') . "<br><br>";

// Get all tables
echo "📋 TABLES IN DATABASE:<br>";
$tables_result = $conn->query("SHOW TABLES");

if ($tables_result) {
    $tables = [];
    while ($row = $tables_result->fetch_row()) {
        $tables[] = $row[0];
        echo "├─ " . $row[0] . "<br>";
    }
    echo "<br>";

    // Now check each table structure
    echo "═══════════════════════════════════════<br>";
    echo "🔎 TABLE DETAILS<br>";
    echo "═══════════════════════════════════════<br><br>";

    // Check for approvals-related tables
    $approval_tables = array_filter($tables, function($t) {
        return stripos($t, 'approval') !== false;
    });

    if (!empty($approval_tables)) {
        echo "Found approval-related tables:<br>";
        foreach ($approval_tables as $table) {
            echo "<br>📊 Table: <strong>$table</strong><br>";
            $columns_result = $conn->query("DESCRIBE $table");
            if ($columns_result) {
                while ($col = $columns_result->fetch_assoc()) {
                    echo "├─ " . $col['Field'] . " (" . $col['Type'] . ")<br>";
                }
            }
        }
    } else {
        echo "❌ No 'approval' tables found!<br>";
    }

    echo "<br>";
    echo "═══════════════════════════════════════<br>";
    echo "📊 USERS TABLE STRUCTURE:<br>";
    echo "═══════════════════════════════════════<br><br>";

    $users_result = $conn->query("DESCRIBE users");
    if ($users_result) {
        while ($col = $users_result->fetch_assoc()) {
            echo "├─ " . $col['Field'] . " (" . $col['Type'] . ")<br>";
        }
    }

    echo "<br>";
    echo "═══════════════════════════════════════<br>";
    echo "Sample data from users table:<br>";
    echo "═══════════════════════════════════════<br><br>";

    $users_data = $conn->query("SELECT nik, name, email, role FROM users LIMIT 5");
    if ($users_data && $users_data->num_rows > 0) {
        while ($row = $users_data->fetch_assoc()) {
            echo "NIK: " . $row['nik'] . " | Name: " . $row['name'] . " | Email: " . $row['email'] . " | Role: " . $row['role'] . "<br>";
        }
    }

} else {
    echo "❌ Error: " . $conn->error;
}

?>
