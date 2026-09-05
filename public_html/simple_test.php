<?php
/**
 * Simple database connection test (HTTP only)
 * Delete after testing
 */

// Load .env
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    $env = parse_ini_file($env_file);
    foreach ($env as $key => $value) {
        putenv("$key=$value");
    }
}

// Database connection (direct mysqli)
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'u910905373_lm';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("<h2 style='color:red;'>❌ Connection failed: " . $conn->connect_error . "</h2>");
}

// Set charset
$conn->set_charset('utf8mb4');

?>
<!DOCTYPE html>
<html>
<head>
    <title>SIPB-GPI Test</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        h1 { color: #2c3e50; }
        table { border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #3498db; color: white; }
    </style>
</head>
<body>

<h1>✅ SIPB-GPI Connection Test</h1>

<p class="success">✓ Database connected successfully!</p>

<?php
// Query admin user
$result = $conn->query("SELECT id, nik, name, email, role FROM users WHERE role = 'superadmin'");

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "<h2>Admin User Found:</h2>";
    echo "<table>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    echo "<tr><td>ID</td><td>" . $user['id'] . "</td></tr>";
    echo "<tr><td>NIK</td><td>" . $user['nik'] . "</td></tr>";
    echo "<tr><td>Name</td><td>" . $user['name'] . "</td></tr>";
    echo "<tr><td>Email</td><td>" . $user['email'] . "</td></tr>";
    echo "<tr><td>Role</td><td>" . $user['role'] . "</td></tr>";
    echo "</table>";
} else {
    echo "<p class='error'>❌ Admin user not found</p>";
}

// Count tables
$dbname = getenv('DB_NAME');
$tables = $conn->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = '$dbname'");
$table_row = $tables->fetch_assoc();
$table_count = $table_row['cnt'] ?? 0;

echo "<p><strong>Database Tables:</strong> " . $table_count . " tables created ✓</p>";

echo "<hr>";
echo "<h3>✓ Setup is Complete!</h3>";
echo "<p>You can now proceed with testing:</p>";
echo "<ul>";
echo "<li><a href='login.php'>Go to Login Page</a> (NIK: 99001, Password: admin123)</li>";
echo "</ul>";
echo "<p><small>Delete this file (simple_test.php) when done</small></p>";

$conn->close();
?>

</body>
</html>
