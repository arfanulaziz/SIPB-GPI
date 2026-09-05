<?php
// Generate bcrypt hash untuk password "test123"
$password = "test123";
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "Password: " . $password . "<br>";
echo "Bcrypt Hash: " . $hash . "<br><br>";
echo "SQL untuk update:<br>";
echo "UPDATE users SET password_hash = '" . $hash . "' WHERE nik = '88001';<br>";

// Verify hash works
echo "<br>Test verify: ";
if (password_verify("test123", $hash)) {
    echo "✅ Hash is CORRECT for password 'test123'";
} else {
    echo "❌ Hash MISMATCH";
}
?>
