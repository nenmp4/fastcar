<?php
/**
 * Marca d'água de rastreio nos PDFs gerados NA HORA (18/11/2026, "aplicar
 * 1 2 3" — item 3, evitar vazamento): relatório do funil, DRE, extrato,
 * relatórios de lucro e o termo de retirada impresso saem com
 * "Gerado por Fulano em 18/11/2026 14:32" no rodapé de cada página — se um
 * print/arquivo aparecer fora da empresa, dá pra saber de onde saiu.
 *
 * Só liga quando o endpoint chama pdfAtivarMarcaDagua() explicitamente,
 * NUNCA sozinho pela sessão: o mesmo gerador cria o contrato que vai pra
 * ZapSign e o DRE do cron do contador, que não podem levar nome de quem
 * clicou. PDF já guardado (contrato assinado, termo de vistoria) não é
 * carimbado — mexer no arquivo assinado invalida a assinatura digital.
 */
require_once __DIR__ . '/fpdf.php';

/** Texto do rodapé de rastreio; '' = desligado. */
function pdfMarcaDaguaTexto(?string $novo = null): string {
    static $texto = '';
    if ($novo !== null) $texto = $novo;
    return $texto;
}

/** Liga a marca d'água pro usuário logado (chamar no endpoint, antes de gerar o PDF). */
function pdfAtivarMarcaDagua(): void {
    $nome = trim((string)($_SESSION['admin_nome'] ?? ''));
    if ($nome === '') $nome = 'usuário #' . (int)($_SESSION['admin_id'] ?? 0);
    pdfMarcaDaguaTexto('Documento interno Fastcar — gerado por ' . $nome . ' em ' . date('d/m/Y H:i') . '. Não compartilhar.');
}

class FastcarPDF extends FPDF {
    function Footer() {
        $texto = pdfMarcaDaguaTexto();
        if ($texto === '') return;
        $this->SetY(-10);
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(140, 140, 150);
        $this->Cell(0, 4, iconv('UTF-8', 'windows-1252//TRANSLIT', $texto), 0, 0, 'C');
    }
}
