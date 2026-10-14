<?php
// Run once in the browser, then DELETE this file. Creates demo admin, doctor and pharmacy.
require 'config.php';
header('Content-Type: text/plain');
$hash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);

q("INSERT IGNORE INTO users (phone,role,password_hash) VALUES ('254700000001','admin',?)", [$hash]);
q("INSERT IGNORE INTO users (phone,role,password_hash) VALUES ('254700000002','doctor',?)", [$hash]);
$id = q("SELECT id FROM users WHERE phone='254700000002'")->fetchColumn();
q("INSERT IGNORE INTO doctors (id,full_name,kmpdc_number,specialty,is_available) VALUES (?,?,?,?,1)", [$id, 'Dr. Amina Otieno', 'KMPDC-DEMO-0001', 'General']);
if (!q('SELECT COUNT(*) FROM pharmacies')->fetchColumn())
  q("INSERT INTO pharmacies (name,location) VALUES ('Changamwe Community Pharmacy','Changamwe, Mombasa')");

echo "Done. Password for both: ChangeMe123!  (change it, then delete setup.php)\nAdmin: 0700000001\nDoctor: 0700000002";
