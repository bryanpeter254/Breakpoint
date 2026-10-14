<?php
// WhatsApp Cloud API webhook: consent -> complaint -> up to 3 AI follow-up questions -> triage -> consultation.
require 'config.php';
require 'wa_config.php';
const FEE = 300, MAX_QUESTIONS = 3;

/* ---------- 1. Meta verification handshake (GET) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals(WA_VERIFY_TOKEN, (string)($_GET['hub_verify_token'] ?? ''))) {
    header('Content-Type: text/plain'); exit($_GET['hub_challenge'] ?? '');
  }
  http_response_code(403); exit;
}

/* ---------- 2. Incoming messages (POST) ---------- */
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, WA_APP_SECRET), $sig)) { http_response_code(401); exit; }

http_response_code(200); echo '{}';           // answer Meta fast; do the work after
header('Connection: close'); header('Content-Length: 2');
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush(); flush(); }

foreach (json_decode($raw, true)['entry'] ?? [] as $e)
  foreach ($e['changes'] ?? [] as $ch)
    foreach ($ch['value']['messages'] ?? [] as $m) {
      try {
        $text = $m['type'] === 'text' ? trim($m['text']['body'] ?? '') : '';
        handle(preg_replace('/\D/', '', $m['from']), $m['id'], $text);
      } catch (Throwable $ex) { error_log('WA bot: ' . $ex->getMessage()); }
    }
exit;

/* ---------- helpers ---------- */
function wa_send($to, $text) {
  $c = curl_init('https://graph.facebook.com/v21.0/' . WA_PHONE_NUMBER_ID . '/messages');
  curl_setopt_array($c, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_TOKEN, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
      'text' => ['body' => mb_substr($text, 0, 4000)]])]);
  curl_exec($c); curl_close($c);
}
function sess($phone, array $f) {            // keys are internal constants, never user input
  $set = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
  q("UPDATE whatsapp_sessions SET $set WHERE phone=?", [...array_values($f), $phone]);
}
function lvl_max($a, $b) { $r = ['GREEN' => 0, 'YELLOW' => 1, 'RED' => 2]; return $r[$a] >= $r[$b] ? $a : $b; }

// Safety net that does NOT depend on the AI: obvious emergencies are always caught (English + Swahili)
function rules($t) {
  $t = mb_strtolower($t);
  $red = ['chest pain', 'maumivu ya kifua', "can't breathe", 'cannot breathe', 'difficulty breathing', 'kupumua', 'unconscious', 'fahamu',
          'seizure', 'convuls', 'kifafa', 'heavy bleeding', 'damu nyingi', 'stroke', 'poison', 'sumu', 'suicid', 'kill myself', 'kujiua'];
  $yel = ['fever', 'homa', 'vomit', 'kutapika', 'diarrh', 'kuhara', 'pain', 'maumivu', 'rash', 'cough', 'kikohozi', 'headache', 'kichwa'];
  foreach ($red as $k) if (str_contains($t, $k)) return ['RED', $k];
  foreach ($yel as $k) if (str_contains($t, $k)) return ['YELLOW', $k];
  return ['GREEN', null];
}

function ai($system, $messages) {
  $c = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($c, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS => json_encode(['model' => ANTHROPIC_MODEL, 'max_tokens' => 700, 'system' => $system, 'messages' => $messages])]);
  $res = json_decode((string)curl_exec($c), true); curl_close($c);
  $t = $res['content'][0]['text'] ?? null;
  if (!$t) return null;
  $j = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($t))), true);
  return is_array($j) ? $j : null;
}

function system_prompt($p) {
  $age = $p['age'] ?? '?';
  return <<<TXT
You are the triage assistant for Breakpoint Healthcare, a telemedicine service in Kenya, talking to a patient on WhatsApp.
You do NOT diagnose or prescribe and never give drug doses. Your job: gather enough information to assign an urgency level so the right doctor sees the patient.

Patient profile: age {$age}, gender {$p['gender']}, chronic conditions: {$p['cond']}, allergies: {$p['allergies']}.

Rules:
- Reply in the patient's language (English, Swahili or simple Sheng). Plain text only, warm, short (under 400 characters), no markdown.
- Ask ONE follow-up question at a time (duration, severity, associated symptoms, pregnancy, red flags relevant to the complaint). Do not repeat questions.
- RED = possible emergency: chest pain, trouble breathing, stroke signs, seizure, heavy bleeding (including in pregnancy), unconsciousness, severe dehydration, poisoning, fever in a baby under 3 months, thoughts of self-harm. When unsure between two levels, choose the more urgent one.
- YELLOW = needs a doctor soon (today / within 24h). GREEN = mild, can wait or self-care.

Output ONLY a JSON object, with no other text:
{"action":"ask","message":"<your next question>"}
or, once you have enough information (or the patient describes a RED situation immediately):
{"action":"triage","level":"RED|YELLOW|GREEN","summary":"<one-line clinical summary in English for the doctor>","reason":"<why this level, English>","message":"<patient-facing reply: what you understood and simple safe self-care advice, in the patient's language>"}
TXT;
}

/* ---------- conversation ---------- */
function handle($phone, $mid, $text) {
  $u = q("SELECT u.id, p.full_name, p.gender, p.allergies, p.chronic_conditions, TIMESTAMPDIFF(YEAR,p.dob,CURDATE()) age
          FROM users u JOIN patients p ON p.id=u.id WHERE u.phone=? AND u.role='patient'", [$phone])->fetch();
  if (!$u) {
    wa_send($phone, "Karibu Breakpoint Healthcare! Please create your free account first at " . WA_SITE_URL . " using this phone number, then message us again.\nTafadhali fungua akaunti kwanza kishaututumie ujumbe tena.");
    return;
  }
  q('INSERT IGNORE INTO whatsapp_sessions (phone) VALUES (?)', [$phone]);
  $s = q('SELECT *, TIMESTAMPDIFF(HOUR,updated_at,NOW()) idle FROM whatsapp_sessions WHERE phone=?', [$phone])->fetch();
  if ($s['last_msg_id'] === $mid) return;                   // Meta retry
  sess($phone, ['last_msg_id' => $mid]);
  $first = explode(' ', $u['full_name'])[0];

  if ($text === '') { wa_send($phone, "Sorry, I can only read text messages for now. Please type your symptoms.\nSamahani, andika dalili zako."); return; }

  // Payment code works in any state: "PAY QJ12ABC345"
  if (preg_match('/^pay\s+([a-z0-9]{10})$/i', $text, $m)) { payment($phone, $u, $s, strtoupper($m[1])); return; }

  // Restart commands, stale (>24h) interviews, or a new message after a finished triage
  $greeting = preg_match('/^(hi|hello|hey|habari|mambo|jambo|start|menu|restart|reset|anza upya)\b/i', $text);
  if (($s['state'] === 'done' && ($greeting || mb_strlen($text) <= 15)) || $greeting || ($s['state'] === 'interview' && $s['idle'] > 24)) {
    if ($s['state'] !== 'consent') {
      sess($phone, ['state' => 'complaint', 'transcript' => null, 'questions_asked' => 0, 'consultation_id' => null]);
      wa_send($phone, "Habari $first! How can we help today? Describe your symptoms (for example: fever for 3 days).\nUnaumwa nini leo?");
      return;
    }
  }

  switch ($s['state']) {
    case 'consent':
      if (in_array(mb_strtolower($text), ['1', 'yes', 'ok', 'ndio', 'ndiyo', 'nakubali'])) {
        sess($phone, ['state' => 'complaint']);
        wa_send($phone, "Thank you $first. Please describe your symptoms (for example: fever for 3 days).\nAsante. Tafadhali elezea dalili zako.");
      } else {
        wa_send($phone, "Karibu Breakpoint Healthcare, $first!\nI'll ask a few questions about your health to find the right doctor. This is not a diagnosis. Your information is kept private and shared only with your doctor (Data Protection Act, 2019). In an emergency call 999 or 112.\n\nReply 1 to agree and continue.\nJibu 1 ukikubali kuendelea.");
      }
      return;

    case 'done': // long message after a finished triage = treat as a new complaint
      sess($phone, ['state' => 'complaint', 'transcript' => null, 'questions_asked' => 0, 'consultation_id' => null]);
      // fall through
    case 'complaint':
      $tr = [['role' => 'user', 'content' => $text]];
      sess($phone, ['state' => 'interview', 'transcript' => json_encode($tr), 'questions_asked' => 0]);
      step($phone, $u, $tr, 0);
      return;

    case 'interview':
      $tr = json_decode($s['transcript'] ?? '[]', true) ?: [];
      $tr[] = ['role' => 'user', 'content' => $text];
      step($phone, $u, $tr, (int)$s['questions_asked']);
      return;
  }
}

function step($phone, $u, $tr, $asked) {
  $said = implode('. ', array_column(array_filter($tr, fn($t) => $t['role'] === 'user'), 'content'));
  [$rl, $rk] = rules($said);
  $profile = ['age' => $u['age'], 'gender' => $u['gender'], 'allergies' => $u['allergies'] ?: 'none',
              'cond' => implode(', ', json_decode((string)$u['chronic_conditions'], true) ?: []) ?: 'none'];
  $force = $asked >= MAX_QUESTIONS;
  $r = ($rl === 'RED') ? null : ai(system_prompt($profile), $tr);   // obvious emergency: don't wait for the AI

  if ($r && ($r['action'] ?? '') === 'ask' && !$force && !empty($r['message'])) {
    $tr[] = ['role' => 'assistant', 'content' => json_encode($r, JSON_UNESCAPED_UNICODE)];
    sess($phone, ['transcript' => json_encode($tr, JSON_UNESCAPED_UNICODE), 'questions_asked' => $asked + 1]);
    wa_send($phone, $r['message']);
    return;
  }

  // Finalise. Level = the more urgent of AI and rules; if the AI failed we fall back to rules alone.
  $aiOk = $r && ($r['action'] ?? '') === 'triage' && in_array($r['level'] ?? '', ['RED', 'YELLOW', 'GREEN']);
  $level = lvl_max($aiOk ? $r['level'] : 'GREEN', $rl);
  if (!$aiOk && $rl === 'GREEN') $level = 'YELLOW';        // unsure + no AI: let a doctor look
  $summary = $aiOk ? ($r['summary'] ?? $said) : $said;
  $status = $level === 'RED' ? 'emergency' : 'waiting_doctor';
  $notes = ['source' => $aiOk ? 'whatsapp_ai' : 'whatsapp_rules', 'matched' => $rk, 'reason' => $aiOk ? ($r['reason'] ?? null) : null,
            'patient_said' => array_values(array_column(array_filter($tr, fn($t) => $t['role'] === 'user'), 'content'))];

  q('INSERT INTO consultations (patient_id,status,chief_complaint,triage_level,triage_notes,consult_type,consultation_fee,source) VALUES (?,?,?,?,?,?,?,?)',
    [$u['id'], $status, mb_substr($summary, 0, 255), $level, json_encode($notes, JSON_UNESCAPED_UNICODE), 'chat', FEE, 'whatsapp']);
  $cid = $GLOBALS['pdo']->lastInsertId();
  sess($phone, ['state' => 'done', 'consultation_id' => $cid, 'transcript' => null]);

  $msg = $aiOk && !empty($r['message']) ? $r['message'] : "Thank you. I've passed your details to our doctors.\nAsante, nimewasilisha taarifa zako kwa madaktari.";
  if ($level === 'RED') {
    $msg .= "\n\n🚨 This may be an emergency. Go to the nearest hospital or call 999 / 112 NOW. A Breakpoint doctor has been alerted.\nHii inaweza kuwa dharura. Nenda hospitali iliyo karibu au piga 999 / 112 sasa.";
  } else {
    $msg .= "\n\nTo see a Breakpoint doctor (KES " . FEE . "): pay by M-Pesa to " . WA_PAY_INSTRUCTIONS . ", then reply:\nPAY <M-Pesa code>\n\nKuonana na daktari: lipa kwa M-Pesa, kisha jibu: PAY <msimbo wa M-Pesa>";
  }
  wa_send($phone, $msg);
}

function payment($phone, $u, $s, $code) {
  $c = $s['consultation_id'] ? q("SELECT id,consultation_fee FROM consultations WHERE id=? AND patient_id=? AND payment_status='unpaid'", [$s['consultation_id'], $u['id']])->fetch() : null;
  if (!$c) { wa_send($phone, "I couldn't find an unpaid consultation. Send 'Hi' to start a new one.\nTuma 'Hi' kuanza upya."); return; }
  try { q('INSERT INTO payments (consultation_id,mpesa_code,amount) VALUES (?,?,?)', [$c['id'], $code, $c['consultation_fee']]); }
  catch (PDOException $e) { wa_send($phone, "That M-Pesa code has already been used. Check it and try again.\nMsimbo huu umeshatumika."); return; }
  wa_send($phone, "Thank you! We're confirming your payment. You'll be seen as soon as it's verified.\nAsante! Tunahakiki malipo yako.");
}
