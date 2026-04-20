<?php
// Raw debug file - bypasses all error handlers
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<h2>✅ PHP is running</h2>';
echo '<p>PHP Version: ' . phpversion() . '</p>';

// Check environment variables
echo '<h3>Environment Variables:</h3><pre>';
$vars = ['DB_HOST','DB_USER','DB_PASS','DB_NAME','APP_DEBUG','BASE_URL','APP_NAME'];
foreach ($vars as $v) {
    $val = $_ENV[$v] ?? getenv($v) ?? 'NOT SET';
    if (str_contains($v,'PASS') || str_contains($v,'PASS')) $val = str_repeat('*', strlen($val));
    echo $v . ' = ' . $val . "\n";
}
echo '</pre>';

// Test DB connection
echo '<h3>Database Connection Test:</h3>';
$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'root';
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';
$name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'railway';

echo "<p>Connecting to: <b>$host</b> / DB: <b>$name</b> / User: <b>$user</b></p>";

$conn = new mysqli($host, $user, $pass, $name);
if ($conn->connect_error) {
    echo '<p style="color:red;">❌ DB Error: ' . $conn->connect_error . '</p>';
} else {
    echo '<p style="color:green;">✅ Database connected!</p>';
    $r = $conn->query("SELECT COUNT(*) as c FROM users");
    if ($r) {
        echo '<p>Users in DB: ' . $r->fetch_assoc()['c'] . '</p>';
    } else {
        echo '<p style="color:red;">❌ users table error: ' . $conn->error . '</p>';
    }
    $conn->close();
}
