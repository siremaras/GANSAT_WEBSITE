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

// Sunucu uyarılarının cevabı bozmaması için: hata metni ekrana basılmaz, yalnızca log'a yazılır
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond($success, $code, $status = 200)
{
    // daha önce basılmış olabilecek uyarıları temizle; yalnızca JSON gönder
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (function_exists('http_response_code')) {
        http_response_code($status);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => $success, 'code' => $code));
    exit;
}

// Tek satırlık alanlar: satır sonlarını kaldır (e-posta başlık enjeksiyonunu önler) ve uzunluğu sınırla
function field($key, $max)
{
    $value = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);
    return trim(mb_substr($value, 0, $max, 'UTF-8'));
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'method', 405);
}

// Bal küpü (honeypot): gerçek kullanıcılar bu gizli alanı boş bırakır
if (!empty($_POST['website'])) {
    respond(true, 'ok');
}

// Basit hız sınırı
// Oturum açılamazsa (ör. sunucuda oturum klasörü sorunu) form yine çalışır, yalnızca bu koruma atlanır
$sessionOk = (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) || @session_start();
if ($sessionOk && isset($_SESSION['contact_last']) && time() - $_SESSION['contact_last'] < $COOLDOWN) {
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
      . "IP       : " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-') . "\n"
      . "----------------------------------------\n\n"
      . $message . "\n";

$encodedSubject = '=?UTF-8?B?' . base64_encode('[gansat.com.tr] ' . $subject) . '?=';

// ---- Gönderim -------------------------------------------------------------
// contact-config.php varsa e-posta, oradaki e-posta hesabı üzerinden SMTP ile
// gönderilir (önerilen). Yoksa sunucunun mail() fonksiyonu denenir.
$smtp = null;
if (is_file(__DIR__ . '/contact-config.php')) {
    $smtp = include __DIR__ . '/contact-config.php';
}

if (is_array($smtp) && !empty($smtp['host'])) {
    $from  = !empty($smtp['from']) ? $smtp['from'] : $smtp['user'];
    $error = '';
    $sent  = smtp_send($smtp, $from, $FROM_NAME, $TO, $email, $encodedSubject, $body, $error);
    if (!$sent) {
        error_log('[contact.php] SMTP gönderimi başarısız: ' . $error);
    }
} else {
    $encodedFrom = '=?UTF-8?B?' . base64_encode($FROM_NAME) . '?=';
    $headers = implode("\r\n", array(
        "From: {$encodedFrom} <{$FROM}>",
        "Reply-To: {$email}",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ));
    // Bazı sunucular gönderen adresinin ayrıca bildirilmesini (-f) ister, bazıları bunu yasaklar: ikisini de dene
    $sent = @mail($TO, $encodedSubject, $body, $headers, '-f' . $FROM)
         || @mail($TO, $encodedSubject, $body, $headers);
    if (!$sent) {
        error_log('[contact.php] mail() başarısız. contact-config.php ile SMTP gönderimi önerilir.');
    }
}

if (!$sent) {
    respond(false, 'mail', 500);
}

if ($sessionOk) {
    $_SESSION['contact_last'] = time();
}
respond(true, 'ok');

/**
 * Basit SMTP istemcisi (AUTH LOGIN, SSL/465 veya STARTTLS/587). Ek kütüphane gerektirmez.
 */
function smtp_send($c, $from, $fromName, $to, $replyTo, $encodedSubject, $body, &$error)
{
    $host   = $c['host'];
    $port   = isset($c['port']) ? (int) $c['port'] : 587;
    $secure = isset($c['secure']) ? strtolower($c['secure']) : 'tls';
    $verify = !isset($c['verify_ssl']) || $c['verify_ssl'];
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify)));

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $error = "bağlantı kurulamadı ($remote): $errstr";
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return array((int) substr($data, 0, 3), trim($data));
    };
    $cmd = function ($line, $expect) use ($fp, $read, &$error) {
        if ($line !== null) {
            fwrite($fp, $line . "\r\n");
        }
        list($code, $text) = $read();
        if (!in_array($code, (array) $expect, true)) {
            $error = 'beklenmeyen cevap: ' . $text;
            return false;
        }
        return true;
    };

    $me = 'gansat.com.tr';
    $ok = $cmd(null, 220) && $cmd('EHLO ' . $me, 250);
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', 220)
           && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
           && $cmd('EHLO ' . $me, 250);
        if (!$ok && $error === '') {
            $error = 'STARTTLS (TLS) başlatılamadı';
        }
    }
    $ok = $ok
       && $cmd('AUTH LOGIN', 334)
       && $cmd(base64_encode($c['user']), 334)
       && $cmd(base64_encode($c['pass']), 235);
    if (!$ok && strpos($error, '535') !== false) {
        $error = 'kullanıcı adı veya şifre kabul edilmedi (535)';
    }
    $ok = $ok
       && $cmd('MAIL FROM:<' . $from . '>', 250)
       && $cmd('RCPT TO:<' . $to . '>', array(250, 251))
       && $cmd('DATA', 354);

    if ($ok) {
        $headers = array(
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: <' . $replyTo . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . md5(uniqid('', true)) . '@gansat.com.tr>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        );
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $data . ".\r\n");
        $ok = $cmd(null, 250);
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
