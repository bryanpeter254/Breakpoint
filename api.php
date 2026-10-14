<?php
require 'config.php';
const FEE = 300;
$a = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$v = fn($k) => trim((string)($in[$k] ?? ''));

// 07xx / 01xx / 2547xx -> 2547xxxxxxxx (null if invalid)
function phone($p) {
  $p = preg_replace('/\D/', '', $p);
  if (preg_match('/^0([17]\d{8})$/', $p, $m)) return '254' . $m[1];
  return preg_match('/^254[17]\d{8}$/', $p) ? $p : null;
}
// Placeholder for the AI triage bot: simple keyword rules. Swap in a real model later.
function triage($t) {
  $t = strtolower($t);
  $red = ['chest pain', 'breath', 'unconscious', 'seizure', 'convuls', 'heavy bleeding', 'stroke', 'poison'];
  $yel = ['fever', 'vomit', 'diarrh', 'pain', 'rash', 'cough', 'headache'];
  foreach ($red as $k) if (str_contains($t, $k)) return ['RED', ['source' => 'rules', 'matched' => $k]];
  foreach ($yel as $k) if (str_contains($t, $k)) return ['YELLOW', ['source' => 'rules', 'matched' => $k]];
  return ['GREEN', ['source' => 'rules', 'matched' => null]];
}
// Consultation the current user may see
function consult($id, $u) {
  $c = q('SELECT c.*, p.full_name patient, p.allergies, p.chronic_conditions, d.full_name doctor
          FROM consultations c JOIN patients p ON p.id=c.patient_id LEFT JOIN doctors d ON d.id=c.doctor_id WHERE c.id=?', [$id])->fetch();
  $ok = $c && ($u['role'] === 'admin' || ($u['role'] === 'patient' && $c['patient_id'] == $u['id'])
        || ($u['role'] === 'doctor' && (!$c['doctor_id'] || $c['doctor_id'] == $u['id'])));
  if (!$ok) out(['error' => 'Consultation not found'], 404);
  return $c;
}
// Consultation that belongs to this doctor and is still open
function own($id, $u) {
  $c = q("SELECT * FROM consultations WHERE id=? AND doctor_id=? AND status='in_progress'", [$id, $u['id']])->fetch();
  if (!$c) out(['error' => 'Not your active consultation'], 404);
  return $c;
}

switch ($a) {
  /* ---------- auth ---------- */
  case 'register': // patients only; doctors/admins are created by an admin
    $ph = phone($v('phone'));
    if (!$ph || strlen($v('password')) < 8 || !$v('full_name') || !$v('id_number') || !$v('dob') || !$v('gender') || !$v('county'))
      out(['error' => 'Fill in name, phone, ID number, date of birth, gender, county and a password of 8+ characters'], 422);
    if (!in_array($v('gender'), ['male', 'female', 'other'])) out(['error' => 'Invalid gender'], 422);
    if (q('SELECT id FROM users WHERE phone=?', [$ph])->fetch()) out(['error' => 'Phone already registered'], 409);
    if (q('SELECT id FROM patients WHERE id_number=?', [$v('id_number')])->fetch()) out(['error' => 'ID number already registered'], 409);
    $conds = array_values(array_filter(array_map('trim', explode(',', $v('chronic_conditions')))));
    $pdo->beginTransaction();
    q("INSERT INTO users (phone,role,password_hash) VALUES (?, 'patient', ?)", [$ph, password_hash($v('password'), PASSWORD_DEFAULT)]);
    $id = $pdo->lastInsertId();
    q('INSERT INTO patients (id,full_name,id_number,dob,gender,county,village,chronic_conditions,allergies) VALUES (?,?,?,?,?,?,?,?,?)',
      [$id, $v('full_name'), $v('id_number'), $v('dob'), $v('gender'), $v('county'), $v('village') ?: null, json_encode($conds), $v('allergies') ?: null]);
    $pdo->commit();
    out(['ok' => true]);

  case 'login':
    $u = q('SELECT u.*, COALESCE(p.full_name, d.full_name, \'Admin\') name FROM users u
            LEFT JOIN patients p ON p.id=u.id LEFT JOIN doctors d ON d.id=u.id WHERE u.phone=?', [phone($v('phone')) ?? ''])->fetch();
    if (!$u || !password_verify($v('password'), $u['password_hash'])) out(['error' => 'Wrong phone or password'], 401);
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role']];
    out($_SESSION['user']);

  case 'logout': session_destroy(); out(['ok' => true]);
  case 'me': out(need());

  /* ---------- patient ---------- */
  case 'start_consult':
    $u = need('patient');
    if (!$v('chief_complaint')) out(['error' => 'Describe your problem'], 422);
    $type = in_array($v('consult_type'), ['video', 'audio', 'chat']) ? $v('consult_type') : 'video';
    [$lvl, $notes] = triage($v('chief_complaint'));
    $status = $lvl === 'RED' ? 'emergency' : 'waiting_doctor'; // triage runs instantly for now
    q('INSERT INTO consultations (patient_id,status,chief_complaint,triage_level,triage_notes,consult_type,consultation_fee) VALUES (?,?,?,?,?,?,?)',
      [$u['id'], $status, $v('chief_complaint'), $lvl, json_encode($notes), $type, FEE]);
    out(['ok' => true, 'triage_level' => $lvl]);

  case 'my_consults':
    $u = need('patient');
    out(q('SELECT c.id,c.status,c.chief_complaint,c.triage_level,c.consult_type,c.payment_status,c.consultation_fee,c.created_at,d.full_name doctor,
           (SELECT status FROM payments WHERE consultation_id=c.id ORDER BY id DESC LIMIT 1) pay_status
           FROM consultations c LEFT JOIN doctors d ON d.id=c.doctor_id WHERE c.patient_id=? ORDER BY c.id DESC', [$u['id']])->fetchAll());

  case 'pay': // patient submits the M-Pesa confirmation code; an admin confirms it
    $u = need('patient');
    $c = q("SELECT * FROM consultations WHERE id=? AND patient_id=? AND payment_status='unpaid'", [(int)$v('consultation_id'), $u['id']])->fetch();
    if (!$c) out(['error' => 'Consultation not found or already paid'], 404);
    $code = strtoupper($v('mpesa_code'));
    if (!preg_match('/^[A-Z0-9]{10}$/', $code)) out(['error' => 'Enter the 10-character M-Pesa code'], 422);
    try { q('INSERT INTO payments (consultation_id,mpesa_code,amount) VALUES (?,?,?)', [$c['id'], $code, $c['consultation_fee']]); }
    catch (PDOException $e) { out(['error' => 'That code was already used'], 409); }
    out(['ok' => true]);

  /* ---------- shared detail ---------- */
  case 'consult_detail':
    $u = need();
    $c = consult((int)($_GET['id'] ?? 0), $u);
    out([
      'consult' => $c,
      'notes' => q('SELECT * FROM consultation_notes WHERE consultation_id=?', [$c['id']])->fetch() ?: null,
      'prescriptions' => q('SELECT p.*, ph.name pharmacy FROM prescriptions p LEFT JOIN pharmacies ph ON ph.id=p.pharmacy_id WHERE consultation_id=?', [$c['id']])->fetchAll(),
      'labs' => q('SELECT lr.*, r.result_value, r.result_pdf_url FROM lab_requests lr LEFT JOIN lab_results r ON r.lab_request_id=lr.id WHERE lr.consultation_id=?', [$c['id']])->fetchAll(),
    ]);

  /* ---------- doctor ---------- */
  case 'queue':
    $u = need('doctor');
    out(q("SELECT c.id,c.doctor_id,c.status,c.chief_complaint,c.triage_level,c.consult_type, p.full_name patient,
           TIMESTAMPDIFF(YEAR,p.dob,CURDATE()) age
           FROM consultations c JOIN patients p ON p.id=c.patient_id
           WHERE c.doctor_id=? AND c.status='in_progress'
              OR (c.doctor_id IS NULL AND (c.status='emergency' OR (c.status='waiting_doctor' AND c.payment_status='paid_via_mpesa')))
           ORDER BY (c.status='emergency') DESC, c.id", [$u['id']])->fetchAll());

  case 'availability':
    $u = need('doctor');
    q('UPDATE doctors SET is_available = NOT is_available WHERE id=?', [$u['id']]);
    out(['available' => (bool)q('SELECT is_available FROM doctors WHERE id=?', [$u['id']])->fetchColumn()]);

  case 'accept':
    $u = need('doctor');
    $s = q("UPDATE consultations SET doctor_id=?, status='in_progress', started_at=NOW(), agora_channel_id=?
            WHERE id=? AND doctor_id IS NULL AND (status='emergency' OR (status='waiting_doctor' AND payment_status='paid_via_mpesa'))",
           [$u['id'], 'bp_' . bin2hex(random_bytes(6)), (int)$v('id')]);
    if (!$s->rowCount()) out(['error' => 'Already taken or not available'], 409);
    out(['ok' => true]);

  case 'save_notes':
    $u = need('doctor');
    $c = own((int)$v('consultation_id'), $u);
    q('INSERT INTO consultation_notes (consultation_id,subjective,objective,assessment,`plan`,created_by_doctor_id) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE subjective=VALUES(subjective), objective=VALUES(objective), assessment=VALUES(assessment), `plan`=VALUES(`plan`)',
      [$c['id'], $v('subjective'), $v('objective'), $v('assessment'), $v('plan'), $u['id']]);
    out(['ok' => true]);

  case 'prescribe':
    $u = need('doctor');
    $c = own((int)$v('consultation_id'), $u);
    if (!$v('medicine_name') || !$v('dosage') || !$v('frequency')) out(['error' => 'Enter medicine, dosage and frequency'], 422);
    q('INSERT INTO prescriptions (consultation_id,medicine_name,dosage,frequency,quantity,instructions_swahili) VALUES (?,?,?,?,?,?)',
      [$c['id'], $v('medicine_name'), $v('dosage'), $v('frequency'), max(1, (int)$v('quantity')), $v('instructions_swahili') ?: null]);
    out(['ok' => true]);

  case 'lab_request':
    $u = need('doctor');
    $c = own((int)$v('consultation_id'), $u);
    if (!$v('test_name')) out(['error' => 'Enter a test name'], 422);
    q('INSERT INTO lab_requests (consultation_id,test_name) VALUES (?,?)', [$c['id'], $v('test_name')]);
    out(['ok' => true]);

  case 'lab_result': // doctor (own consultation) or admin
    $u = need();
    if (!in_array($u['role'], ['doctor', 'admin'])) out(['error' => 'Not allowed'], 403);
    $lr = q('SELECT lr.id, c.doctor_id FROM lab_requests lr JOIN consultations c ON c.id=lr.consultation_id WHERE lr.id=?', [(int)$v('lab_request_id')])->fetch();
    if (!$lr || ($u['role'] === 'doctor' && $lr['doctor_id'] != $u['id'])) out(['error' => 'Lab request not found'], 404);
    if (!$v('result_value') && !$v('result_pdf_url')) out(['error' => 'Enter a result'], 422);
    q('INSERT INTO lab_results (lab_request_id,result_value,result_pdf_url,uploaded_by) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE result_value=VALUES(result_value), result_pdf_url=VALUES(result_pdf_url), uploaded_by=VALUES(uploaded_by)',
      [$lr['id'], $v('result_value') ?: null, $v('result_pdf_url') ?: null, $u['id']]);
    q("UPDATE lab_requests SET status='completed' WHERE id=?", [$lr['id']]);
    out(['ok' => true]);

  case 'complete':
    $u = need('doctor');
    $c = own((int)$v('consultation_id'), $u);
    q("UPDATE consultations SET status='completed', ended_at=NOW() WHERE id=?", [$c['id']]);
    out(['ok' => true]);

  /* ---------- admin ---------- */
  case 'admin_overview':
    need('admin');
    out([
      'payments' => q("SELECT pay.*, pt.full_name patient FROM payments pay JOIN consultations c ON c.id=pay.consultation_id
                       JOIN patients pt ON pt.id=c.patient_id WHERE pay.status='pending' ORDER BY pay.id")->fetchAll(),
      'prescriptions' => q('SELECT p.*, pt.full_name patient FROM prescriptions p JOIN consultations c ON c.id=p.consultation_id
                            JOIN patients pt ON pt.id=c.patient_id WHERE p.is_dispensed=0 ORDER BY p.id')->fetchAll(),
      'pharmacies' => q('SELECT id,name FROM pharmacies')->fetchAll(),
    ]);

  case 'confirm_payment':
    need('admin');
    $p = q("SELECT * FROM payments WHERE id=? AND status='pending'", [(int)$v('id')])->fetch();
    if (!$p) out(['error' => 'Payment not found'], 404);
    $pdo->beginTransaction();
    q("UPDATE payments SET status='confirmed' WHERE id=?", [$p['id']]);
    q("UPDATE consultations SET payment_status='paid_via_mpesa' WHERE id=?", [$p['consultation_id']]);
    $pdo->commit();
    out(['ok' => true]);

  case 'dispense':
    need('admin');
    if (!q('SELECT id FROM pharmacies WHERE id=?', [(int)$v('pharmacy_id')])->fetch()) out(['error' => 'Choose a pharmacy'], 422);
    q('UPDATE prescriptions SET is_dispensed=1, pharmacy_id=? WHERE id=?', [(int)$v('pharmacy_id'), (int)$v('id')]);
    out(['ok' => true]);

  case 'add_doctor':
    need('admin');
    $ph = phone($v('phone'));
    if (!$ph || strlen($v('password')) < 8 || !$v('full_name') || !$v('kmpdc_number')) out(['error' => 'Enter phone, name, KMPDC number and a password of 8+ characters'], 422);
    if (q('SELECT id FROM users WHERE phone=?', [$ph])->fetch()) out(['error' => 'Phone already registered'], 409);
    if (q('SELECT id FROM doctors WHERE kmpdc_number=?', [$v('kmpdc_number')])->fetch()) out(['error' => 'KMPDC number already registered'], 409);
    $pdo->beginTransaction();
    q("INSERT INTO users (phone,role,password_hash) VALUES (?, 'doctor', ?)", [$ph, password_hash($v('password'), PASSWORD_DEFAULT)]);
    q('INSERT INTO doctors (id,full_name,kmpdc_number,specialty,license_doc_url) VALUES (?,?,?,?,?)',
      [$pdo->lastInsertId(), $v('full_name'), $v('kmpdc_number'), $v('specialty') ?: 'General', $v('license_doc_url') ?: null]);
    $pdo->commit();
    out(['ok' => true]);

  default: out(['error' => 'Unknown action'], 404);
}
