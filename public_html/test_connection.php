<?php
/**
 * Test database connection
 * Delete this file after testing
 */

session_start();
include __DIR__ . '/config/config.php';

echo "<h1>SIPB-GPI - Database Connection Test</h1>";
echo "<hr>";

try {
    // Test database connection
    $db = new Database($conn);

    // Query admin user
    $admin = $db->getRow("SELECT id, nik, name, email, role FROM users WHERE role = ?", ['superadmin']);

    if ($admin) {
        echo "<h2 style='color: green;'>✓ Database Connection OK!</h2>";
        echo "<p><strong>Admin User Found:</strong></p>";
        echo "<ul>";
        echo "<li>NIK: " . htmlspecialchars($admin['nik']) . "</li>";
        echo "<li>Name: " . htmlspecialchars($admin['name']) . "</li>";
        echo "<li>Email: " . htmlspecialchars($admin['email']) . "</li>";
        echo "<li>Role: " . htmlspecialchars($admin['role']) . "</li>";
        echo "</ul>";

        // Count tables
        $result = $db->query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = ?", ['u910905373_lm']);
        $table_count = 0;
        while ($row = $result->fetch_assoc()) {
            $table_count++;
        }

        echo "<p><strong>Database Tables:</strong> " . $table_count . " tables</p>";

        echo "<h3>Ready to Login</h3>";
        echo "<p><a href='login.php' style='padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Go to Login Page</a></p>";
    } else {
        echo "<h2 style='color: red;'>✗ Admin user not found</h2>";
    }

} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ Connection Failed</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><small>Delete this file (test_connection.php) after testing</small></p>";
?>
