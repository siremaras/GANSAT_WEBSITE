<?php
/**
 * İletişim formu e-posta (SMTP) ayarları — ÖRNEK DOSYA
 *
 * Kullanım:
 *   1. Bu dosyanın bir kopyasını oluşturup adını  contact-config.php  yapın.
 *   2. Aşağıdaki bilgileri Natro'daki e-posta hesabınıza göre doldurun.
 *   3. contact-config.php dosyasını sunucuda contact.php ile aynı klasöre yükleyin.
 *
 * contact-config.php şifre içerdiği için GitHub'a YÜKLENMEZ (.gitignore'da).
 * Bu örnek dosyada gerçek şifre yazmayın.
 */
return array(
    'host'   => 'mail.gansat.com.tr',     // Natro panelindeki "giden posta (SMTP) sunucusu"
    'port'   => 587,                      // 587 (TLS) veya 465 (SSL)
    'secure' => 'tls',                    // 587 için 'tls', 465 için 'ssl'
    'user'   => 'sales@gansat.com.tr',    // e-posta hesabının tam adresi
    'pass'   => 'BURAYA-SIFRE',           // e-posta hesabının şifresi
    'from'   => '',                       // boş bırakılırsa 'user' adresi kullanılır
    // 'verify_ssl' => false,             // yalnızca SSL sertifika hatası alınırsa açın
);
