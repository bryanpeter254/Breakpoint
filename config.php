<?php
// Edit these for your server
const DB_HOST = 'localhost', DB_NAME = 'breakpoint', DB_USER = 'root', DB_PASS = '';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');

try {
  $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  exit(json_encode(['error' => 'Database connection failed']));
}

function out($d, $c = 200) { http_response_code($c); echo json_encode($d); exit; }
function q($sql, $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function need($role = null) {
  $u = $_SESSION['user'] ?? null;
  if (!$u) out(['error' => 'Please sign in'], 401);
  if ($role && $u['role'] !== $role) out(['error' => 'Not allowed'], 403);
  return $u;
}
