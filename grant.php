<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<h2>🔧 Fixing MySQL Permissions...</h2>';

// Parse the public URL to connect from outside the internal network
$public_url = getenv('MYSQL_PUBLIC_URL') ?: getenv('MYSQLPUBLICURL') ?: '';

if ($public_url) {
    $parts = parse_url($public_url);
    $host  = $parts['host'];
    $port  = $parts['port'] ?? 3306;
    $user  = $parts['user'];
    $pass  = $parts['pass'];
    $db    = ltrim($parts['path'], '/');
} else {
    // fallback to internal
    $host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
    $port = (int)(getenv('MYSQLPORT') ?: 3306);
    $user = getenv('MYSQLUSER') ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: '';
    $db   = getenv('MYSQLDATABASE') ?: 'railway';
}

echo "<p>Connecting to: <b>$host:$port</b></p>";

$conn = new mysqli($host, $user, $pass, $db, (int)$port);

if ($conn->connect_error) {
    echo '<p style="color:red;">❌ Connection failed: ' . $conn->connect_error . '</p>';
    exit;
}

echo '<p style="color:green;">✅ Connected!</p>';

$sqls = [
    "GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION",
    "FLUSH PRIVILEGES",
    "INSERT IGNORE INTO `users` (`full_name`,`username`,`password`,`role`,`email`) VALUES ('Administrator','admin','\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin','admin@dentalclinic.com')",
    "INSERT IGNORE INTO `services` (`service_name`,`description`,`duration_minutes`,`price`) VALUES ('Dental Checkup','General oral examination',30,300.00),('Tooth Extraction','Simple or surgical tooth removal',45,500.00),('Dental Cleaning','Prophylaxis / scaling',60,800.00),('Tooth Filling','Composite or amalgam filling',45,600.00),('Root Canal','Endodontic treatment',90,3500.00),('Teeth Whitening','Bleaching treatment',60,2500.00)",
];

foreach ($sqls as $sql) {
    $label = substr($sql, 0, 50) . '...';
    if ($conn->query($sql)) {
        echo '<p style="color:green;">✅ ' . htmlspecialchars($label) . '</p>';
    } else {
        echo '<p style="color:orange;">⚠ ' . htmlspecialchars($label) . ' — ' . $conn->error . '</p>';
    }
}

$conn->close();
echo '<h2 style="color:green;">✅ Done! Now go to <a href="/">your site</a> and login with admin / password</h2>';
