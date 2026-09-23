<?php

namespace EmbedForms\Tests;

use EmbedForms\Admin\CsvExport;
use EmbedForms\Notifications\MergeTags;
use EmbedForms\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class MergeTagsTest extends TestCase {

  private function context(): array {
    return [
      'form' => ['title' => 'Signup'],
      'entry' => ['id' => 5, 'source_url' => 'https://example.org/x'],
      'schema' => FormSchema::normalize(['fields' => [
        ['id' => 'name', 'type' => 'name', 'label' => 'Name'],
        ['id' => 'color', 'type' => 'select', 'label' => 'Color', 'options' => [['label' => 'Bright red', 'value' => 'r']]],
        ['id' => 'note', 'type' => 'textarea', 'label' => 'Note'],
      ]]),
      'values' => ['name' => ['first' => 'Ada', 'last' => 'L&L'], 'color' => 'r', 'note' => "<script>x</script>\nhi"],
    ];
  }

  public function testTextAndHtml(): void {
    $c = $this->context();
    self::assertSame('Signup #5 Ada L&L Bright red Ada', MergeTags::replace('{form_title} #{entry_id} {field:name} {field:color} {field:name:first}', $c, FALSE));
    self::assertSame('Ada L&amp;L', MergeTags::replace('{field:name}', $c, TRUE));
    self::assertStringContainsString('&lt;script&gt;', MergeTags::replace('{field:note}', $c, TRUE));
    self::assertSame('{nope} {field:missing}', MergeTags::replace('{nope} {field:missing}', $c, FALSE));
  }

  public function testUrlEncodesAnswers(): void {
    self::assertSame('https://x.test/?n=Ada%20L%26L', MergeTags::replaceUrl('https://x.test/?n={field:name}', $this->context()));
  }

  public function testAllFieldsTable(): void {
    $html = MergeTags::replace('{all_fields}', $this->context(), TRUE);
    self::assertStringContainsString('<table', $html);
    self::assertStringContainsString('Bright red', $html);
    self::assertStringNotContainsString('<script>', $html);
  }

  public function testCsvCellsCannotBeFormulas(): void {
    self::assertSame("'=SUM(A1)", CsvExport::cell('=SUM(A1)'));
    self::assertSame("'+1", CsvExport::cell('+1'));
    self::assertSame('plain', CsvExport::cell('plain'));
  }

}
