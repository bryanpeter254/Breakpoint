<?php
// Run once in the browser, read the result, then DELETE this file.
require 'config.php';
header('Content-Type: text/plain; charset=utf-8');
echo "1. Database connection: OK (" . DB_NAME . ")\n";
try {
  $cols = array_column(q('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
  echo "2. users table columns: " . implode(', ', $cols) . "\n";
  if (!in_array('phone', $cols)) exit("   PROBLEM: this is the OLD schema (no phone column). DROP the 'breakpoint' database, import database.sql again, then run setup.php.\n");
  $rows = q('SELECT phone, role, password_hash FROM users')->fetchAll();
  echo "3. users in database: " . count($rows) . "\n";
  if (!$rows) echo "   PROBLEM: no users. Run setup.php once.\n";
  foreach ($rows as $r)
    echo "   {$r['phone']} ({$r['role']}) - password ChangeMe123! " . (password_verify('ChangeMe123!', $r['password_hash']) ? 'MATCHES' : 'does NOT match') . "\n";
} catch (PDOException $e) {
  exit("2. PROBLEM reading users table: " . $e->getMessage() . "\n   Import database.sql into the 'breakpoint' database.\n");
}
$js  = @file_get_contents('app.js');
$api = @file_get_contents('api.php');
echo "4. app.js on the server uses phone login: " . (strpos((string)$js, "api('login', {phone") !== false ? 'YES' : 'NO - this is an old app.js, replace it') . "\n";
echo "5. api.php on the server uses phone login: " . (strpos((string)$api, "phone(\$v('phone'))") !== false ? 'YES' : 'NO - this is an old api.php, replace it') . "\n";
echo "\nIf 1-5 all look good, press Ctrl+F5 in the browser and sign in with 0700000001.\n";
