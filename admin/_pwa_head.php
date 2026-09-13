<?php /**
 * Tags de PWA — include no <head> de toda página cheia do admin (não em
 * login.php, mesmo padrão do JurídicoSaaS: o service worker registrado numa
 * página autenticada já cobre o app instalado, não precisa repetir na tela
 * de login). Ver admin/_pwa_register.php pro script de registro.
 */ ?>
<link rel="manifest" href="/admin/manifest.json">
<meta name="theme-color" content="#1e293b">
<link rel="apple-touch-icon" href="/admin/assets/img/icon-192.png">
<link rel="icon" type="image/png" href="/admin/assets/img/favicon.png">
