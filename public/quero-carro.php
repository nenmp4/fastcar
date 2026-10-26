<?php
// Endereço antigo — o formulário mora na raiz do site público (fastcar.solutions/quero-carro.php).
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: https://fastcar.solutions/quero-carro.php' . ($q !== '' ? '?' . $q : ''), true, 301);
exit;
