<?php

namespace EmbedForms\Sheet;

/**
 * A one-sheet Excel workbook: a bold header row, then rows of text and
 * numbers. Text is written as inline strings, so nothing runs as a formula.
 * Needs PHP's zip extension.
 */
final class Xlsx {

  public static function available(): bool {
    return class_exists(\ZipArchive::class);
  }

  /**
   * Write a workbook to a file.
   *
   * @param string[] $header
   * @param array<int, array<int, string|int|float>> $rows
   */
  public static function write(string $file, string $sheetName, array $header, array $rows): void {
    $zip = new \ZipArchive();
    if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException('The Excel file could not be created.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets><sheet name="' . self::xml(self::sheetName($sheetName)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
      . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
      . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
      . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
      . '</styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($header, $rows));
    $zip->close();
  }

  /**
   * The worksheet XML: header frozen at the top.
   */
  public static function sheet(array $header, array $rows): string {
    $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
      . '<sheetData>';
    foreach (array_merge([$header], $rows) as $r => $row) {
      $out .= '<row r="' . ($r + 1) . '">';
      foreach (array_values($row) as $c => $value) {
        $ref = self::column($c) . ($r + 1);
        $style = $r === 0 ? ' s="1"' : '';
        if ((is_int($value) || is_float($value)) && $r > 0) {
          $out .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
        }
        elseif ((string) $value !== '') {
          $out .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . self::xml((string) $value) . '</t></is></c>';
        }
      }
      $out .= '</row>';
    }
    return $out . '</sheetData></worksheet>';
  }

  public static function column(int $index): string {
    $letter = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
      $letter = chr(65 + ($n - 1) % 26) . $letter;
    }
    return $letter;
  }

  private static function sheetName(string $name): string {
    $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
    return mb_substr($name !== '' ? $name : 'Entries', 0, 31);
  }

  /**
   * Text escaped for XML, without the control characters XML forbids.
   */
  private static function xml(string $text): string {
    $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

}
