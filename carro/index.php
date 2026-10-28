<?php
/**
 * Link curto do formulário de vendas (28/10/2026, "esse link ficou grande né").
 * fastcar.solutions/carro?r → /quero-carro.php com a origem certa, sem mostrar
 * os parâmetros utm_... compridos na mensagem do WhatsApp. Códigos:
 *   r = follow-up de recuperação (leads que ficaram sem resposta)
 *   w = lead que chamou direto no WhatsApp sem ter preenchido
 * Qualquer outro parâmetro (utm_* de anúncio) passa adiante sem mudança.
 */
$codigos = [
    'r' => ['utm_source' => 'whatsapp', 'utm_campaign' => 'followup_recuperacao'],
    'w' => ['utm_source' => 'whatsapp'],
];
$params = [];
foreach ($_GET as $k => $v) {
    if (isset($codigos[$k])) $params += $codigos[$k];
    elseif (is_string($v) && $v !== '') $params[$k] = $v;
}
header('Location: /quero-carro.php' . ($params ? '?' . http_build_query($params) : ''), true, 302);
exit;
