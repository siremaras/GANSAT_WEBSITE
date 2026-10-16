<?php
/**
 * Gansat Teknoloji — iletişim formu
 *
 * index.html içindeki #contactForm bu dosyaya POST eder ve JSON yanıt bekler:
 *   { "success": true,  "code": "ok" }
 *   { "success": false, "code": "invalid" | "rate" | "mail" | "method" }
 * Mesaj metinleri (TR/EN) sayfadaki JavaScript tarafında gösterilir.
 */

// ---- Ayarlar -------------------------------------------------------------
$TO        = 'sales@gansat.com.tr';      // Form mesajlarının gideceği adres
$FROM      = 'noreply@gansat.com.tr';    // Alan adınıza ait bir gönderen adresi olmalı
$FROM_NAME = 'Gansat Web Sitesi';
$COOLDOWN  = 30;                          // Aynı ziyaretçi için iki gönderim arası (saniye)
// -------------------------------------------------------------------------

// mbstring eklentisi kapalı olan sunucular için UTF-8 uyumlu yedek fonksiyonlar
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return (int) preg_match_all('/./us', (string) $s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null)
    {
        preg_match_all('/./us', (string) $s, $m);
        return implode('', array_slice($m[0], $start, $length));
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond($success, $code, $status = 200)
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'code' => $code]);
    exit;
}

// Tek satırlık alanlar: satır sonlarını kaldır (e-posta başlık enjeksiyonunu önler) ve uzunluğu sınırla
function field($key, $max)
{
    $value = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);
    return trim(mb_substr($value, 0, $max, 'UTF-8'));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'method', 405);
}

// Bal küpü (honeypot): gerçek kullanıcılar bu gizli alanı boş bırakır
if (!empty($_POST['website'])) {
    respond(true, 'ok');
}

// Basit hız sınırı
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['contact_last']) && time() - $_SESSION['contact_last'] < $COOLDOWN) {
    respond(false, 'rate', 429);
}

$name    = field('name', 100);
$email   = field('email', 254);
$phone   = field('phone', 30);
$subject = field('subject', 150);
$message = isset($_POST['message']) ? trim(mb_substr((string) $_POST['message'], 0, 5000, 'UTF-8')) : '';

if (
    mb_strlen($name, 'UTF-8') < 2 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    mb_strlen($subject, 'UTF-8') < 2 ||
    mb_strlen($message, 'UTF-8') < 10 ||
    ($phone !== '' && !preg_match('/^[0-9+()\s.-]{6,30}$/', $phone))
) {
    respond(false, 'invalid', 422);
}

$body = "Web sitesi iletişim formundan yeni mesaj\n"
      . "----------------------------------------\n"
      . "Ad Soyad : {$name}\n"
      . "E-posta  : {$email}\n"
      . "Telefon  : " . ($phone !== '' ? $phone : '-') . "\n"
      . "Konu     : {$subject}\n"
      . "Tarih    : " . date('d.m.Y H:i') . "\n"
      . "IP       : " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n"
      . "----------------------------------------\n\n"
      . $message . "\n";

$encodedSubject = '=?UTF-8?B?' . base64_encode('[gansat.com.tr] ' . $subject) . '?=';
$encodedFrom    = '=?UTF-8?B?' . base64_encode($FROM_NAME) . '?=';

$headers = implode("\r\n", [
    "From: {$encodedFrom} <{$FROM}>",
    "Reply-To: {$email}",
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

if (!mail($TO, $encodedSubject, $body, $headers)) {
    respond(false, 'mail', 500);
}

$_SESSION['contact_last'] = time();
respond(true, 'ok');
