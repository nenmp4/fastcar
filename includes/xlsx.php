<?php
/**
 * includes/xlsx.php — gerador mínimo de planilha Excel (.xlsx) em PHP puro.
 * 13/11/2026, "gerar relatório financeiro para gerar em excel". Sem
 * Composer/biblioteca (mesmo espírito do FPDF puro dos contratos): monta o
 * XML do SpreadsheetML na mão e compacta com ZipArchive (extensão padrão,
 * já usada pelo backup). Suporta várias abas, texto, número, data (célula
 * de data real, ordenável/filtrável no Excel), moeda e linha em negrito.
 *
 * Uso:
 *   $x = new XlsxSimples();
 *   $x->aba('Lançamentos', [30, 12], [
 *       [['v'=>'Descrição','s'=>'cab'], ['v'=>'Valor','s'=>'cab']],
 *       ['Aluguel', ['v'=>1500.0,'s'=>'moeda']],
 *   ]);
 *   $bytes = $x->gerar();
 *
 * Célula = valor cru (string/int/float/null) ou ['v'=>..., 's'=>estilo].
 * Estilos: 'cab' (negrito, fundo cinza), 'negrito', 'moeda', 'moeda_negrito',
 * 'data' (v = 'AAAA-MM-DD'), 'titulo' (negrito maior).
 */

class XlsxSimples
{
    private array $abas = [];

    private const ESTILOS = ['' => 0, 'cab' => 1, 'negrito' => 2, 'moeda' => 3, 'moeda_negrito' => 4, 'data' => 5, 'titulo' => 6];

    public function aba(string $nome, array $larguras, array $linhas): void
    {
        // Excel: nome de aba até 31 caracteres, sem []:*?/\
        $nome = mb_substr(preg_replace('#[\[\]:*?/\\\\]#', ' ', $nome), 0, 31);
        $this->abas[] = ['nome' => $nome, 'larguras' => $larguras, 'linhas' => $linhas];
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    private static function esc(string $t): string
    {
        // remove caractere de controle inválido em XML 1.0
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t);
        return htmlspecialchars($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function dataSerial(string $data): ?float
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m)) return null;
        $ts = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        return round($ts / 86400 + 25569);
    }

    private function sheetXml(array $aba): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($aba['larguras']) {
            $x .= '<cols>';
            foreach ($aba['larguras'] as $i => $w) {
                $n = $i + 1;
                $x .= '<col min="' . $n . '" max="' . $n . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach (array_values($aba['linhas']) as $r => $linha) {
            $rn = $r + 1;
            $x .= '<row r="' . $rn . '">';
            foreach (array_values($linha) as $c => $cel) {
                $ref = self::col($c) . $rn;
                $estilo = '';
                $v = $cel;
                if (is_array($cel)) { $v = $cel['v'] ?? null; $estilo = (string)($cel['s'] ?? ''); }
                $sIdx = self::ESTILOS[$estilo] ?? 0;
                $sAttr = $sIdx ? ' s="' . $sIdx . '"' : '';
                if ($v === null || $v === '') {
                    if ($sIdx) $x .= '<c r="' . $ref . '"' . $sAttr . '/>';
                    continue;
                }
                if ($estilo === 'data') {
                    $serial = self::dataSerial((string)$v);
                    if ($serial !== null) { $x .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $serial . '</v></c>'; continue; }
                    $sAttr = '';
                }
                if (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $sAttr . '><v>' . (is_float($v) ? round($v, 2) : $v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData></worksheet>';
        return $x;
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;R$&quot; #,##0.00;[Red]-&quot;R$&quot; #,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** Devolve os bytes do .xlsx. Lança RuntimeException se o ZipArchive falhar. */
    public function gerar(): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Extensão zip do PHP não disponível.');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não deu pra montar o arquivo Excel.');

        $n = count($this->abas);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::esc($this->abas[$i - 1]['nome']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $this->sheetXml($this->abas[$i - 1]));
        }
        $ct .= '</Types>';
        $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();

        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
