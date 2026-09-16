<?php
/**
 * includes/email_templates.php — moldura visual compartilhada pros e-mails
 * transacionais (16/09/2026, pedido José/Jean: "de email bem top" — mesma
 * identidade visual já usada no wizard de documentos e no cabeçalho do PDF
 * do contrato: faixa navy #151722 com a logo, botão em azul #2f6fed,
 * rodapé com o endereço real da sede — reforça pro cliente que não é golpe,
 * mesma preocupação já coberta no wizard e no prompt da IA sobre
 * desconfiança com link/dado financeiro).
 *
 * Estilo sempre inline (nunca <style> externo/bloco) — clientes de e-mail
 * (Gmail, Outlook) removem ou ignoram CSS não-inline; gradiente evitado no
 * corpo do e-mail pelo mesmo motivo (Outlook desktop não renderiza
 * `linear-gradient`), cor sólida em vez disso.
 */

require_once __DIR__ . '/marca.php';

/** Base pra montar link absoluto — mesmo padrão de admin/oportunidade.php,
 *  com fallback pro domínio de produção pra nunca gerar link quebrado
 *  quando chamado fora de um request HTTP (ex: mudarEtapa() por um cron). */
function appBaseUrl(): string {
    $configurado = getConfig('app_base_url');
    if ($configurado) return rtrim($configurado, '/');
    if (!empty($_SERVER['HTTP_HOST'])) {
        return (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    }
    return 'https://fastcar.solutions';
}

/** Botão de call-to-action — tabela em vez de <a> com padding puro, mesmo
 *  truque clássico de e-mail HTML pra renderizar consistente no Outlook. */
function emailBotao(string $texto, string $url): string {
    $url = htmlspecialchars($url, ENT_QUOTES);
    $texto = htmlspecialchars($texto, ENT_QUOTES);
    return <<<HTML
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0">
            <tr>
                <td style="background-color:#2f6fed;border-radius:8px">
                    <a href="{$url}" style="display:inline-block;padding:14px 28px;font-family:Arial,sans-serif;
                       font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px">
                        {$texto}
                    </a>
                </td>
            </tr>
        </table>
        HTML;
}

/** Moldura completa (cabeçalho com logo/wordmark + corpo + rodapé com endereço). */
function emailLayout(string $corpoHtml): string {
    $logoUrl = marcaLogoConfigurada() ? appBaseUrl() . '/public/assets/logo.png' : null;
    $cabecalho = $logoUrl
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="Fastcar" height="40" style="display:block;height:40px">'
        : '<span style="font-family:Arial,sans-serif;font-size:22px;font-weight:bold;color:#ffffff">Fast<span style="color:#5b8dff">Car</span></span>';

    return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <body style="margin:0;padding:0;background-color:#f4f4f7;font-family:Arial,Helvetica,sans-serif">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f7;padding:24px 0">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellspacing="0" cellpadding="0"
                               style="background-color:#ffffff;border-radius:10px;overflow:hidden;max-width:600px;width:100%">
                            <tr>
                                <td style="background-color:#151722;padding:24px 32px">
                                    {$cabecalho}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:32px;color:#1a1a1a;font-size:15px;line-height:1.6">
                                    {$corpoHtml}
                                </td>
                            </tr>
                            <tr>
                                <td style="background-color:#f4f4f7;padding:20px 32px;border-top:3px solid #2f6fed">
                                    <p style="margin:0;font-size:11.5px;color:#6b7280;line-height:1.5">
                                        <strong style="color:#151722">FASTCAR SOLUTIONS</strong><br>
                                        Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2),
                                        Complexo Alpha Square Offices<br>
                                        Alphaville Conde II, Barueri/SP — CEP 06473-073
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
}
