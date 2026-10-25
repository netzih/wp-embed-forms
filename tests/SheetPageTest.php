<?php

namespace EmbedForms\Tests;

use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\FormSettings;
use EmbedForms\Sheet\Grid;
use EmbedForms\Sheet\Xlsx;
use PHPUnit\Framework\TestCase;

final class SheetPageTest extends TestCase {

  private function schema(): array {
    return FormSchema::normalize(['fields' => [
      ['id' => 'name', 'type' => 'name', 'label' => 'Name'],
      ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
      ['id' => 'pickup', 'type' => 'radio', 'label' => 'Delivery', 'options' => [['value' => 'pickup', 'label' => 'Pick up'], ['value' => 'ship', 'label' => 'Ship']]],
      ['id' => 'street', 'type' => 'text', 'label' => 'Street', 'conditions' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'pickup', 'operator' => 'is', 'value' => 'ship']]]],
      ['id' => 'copies', 'type' => 'product', 'label' => 'Copies', 'price' => '24.99', 'quantity' => TRUE],
      ['id' => 'tags', 'type' => 'checkbox', 'label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'A']]],
    ]]);
  }

  private function column(array $columns, string $key): array {
    foreach ($columns as $column) {
      if ($column['key'] === $key) {
        return $column;
      }
    }
    self::fail('No column ' . $key);
  }

  public function testColumnsSayWhatCanChange(): void {
    $columns = Grid::columns(FormSchema::inputs($this->schema()));
    $edit = array_column($columns, 'edit', 'label');
    self::assertNull($edit['Entry']);
    self::assertNull($edit['Amount']);
    self::assertNull($edit['Payment']);
    // A store form shows the order; only the manager page changes it.
    $store = array_column(Grid::columns(FormSchema::inputs($this->schema()), ['store' => TRUE]), 'edit', 'key');
    self::assertNull($store['order']);
    self::assertArrayNotHasKey('tracking', $store);
    $manager = array_column(Grid::columns(FormSchema::inputs($this->schema()), ['store' => TRUE, 'manager' => TRUE]), 'edit', 'key');
    self::assertSame('select', $manager['order']);
    self::assertSame('text', $manager['tracking']);
    self::assertNull($manager['balance']);
    self::assertSame('text', $edit['Name (first)']);
    self::assertSame('email', $edit['Email (2)']);
    self::assertSame('select', $edit['Delivery']);
    self::assertNull($edit['Copies']);
    self::assertNull($edit['Tags']);
    self::assertNull($edit['Card']);
    self::assertSame([['pickup', 'Pick up'], ['ship', 'Ship']], $this->column($columns, 'f:pickup')['options']);
  }

  public function testChangingOneCellLeavesTheRestAlone(): void {
    $schema = $this->schema();
    $columns = Grid::columns(FormSchema::inputs($schema));
    $data = ['name' => ['first' => 'Dana', 'last' => 'Test'], 'email' => 'd@example.org', 'pickup' => 'ship', 'street' => '770 Eastern', 'copies' => '3', 'tags' => ['a']];

    $result = Grid::change($schema, $data, $this->column($columns, 'f:name:first'), '  Chana ');
    self::assertTrue($result['ok']);
    self::assertSame(['first' => 'Chana', 'last' => 'Test'], $result['values']['name']);
    self::assertSame($data['copies'], $result['values']['copies']);
    self::assertSame($data['street'], $result['values']['street']);

    // Picking "Pick up" would hide the street; its answer is kept anyway.
    $result = Grid::change($schema, $data, $this->column($columns, 'f:pickup'), 'pickup');
    self::assertSame('pickup', $result['values']['pickup']);
    self::assertSame('770 Eastern', $result['values']['street']);

    self::assertFalse(Grid::change($schema, $data, $this->column($columns, 'f:email'), 'not an email')['ok']);
    self::assertFalse(Grid::change($schema, $data, $this->column($columns, 'f:pickup'), 'teleport')['ok']);
    self::assertFalse(Grid::change($schema, $data, $this->column($columns, 'f:copies'), '9')['ok']);
    self::assertFalse(Grid::change($schema, $data, $this->column($columns, 'amount'), '1')['ok']);

    $hidden = Grid::change($schema, ['pickup' => 'pickup'] + $data, $this->column($columns, 'f:street'), 'Elsewhere');
    self::assertFalse($hidden['ok']);
  }

  public function testCellsShowLabelsAndPayments(): void {
    $schema = $this->schema();
    $inputs = FormSchema::inputs($schema);
    $columns = Grid::columns($inputs);
    $entry = ['id' => 7, 'status' => 'paid', 'payer_email' => 'd@example.org', 'amount' => '74.97', 'data' => ['pickup' => 'ship', 'name' => ['first' => 'Dana']]];
    $payment = ['gateway' => 'stripe', 'mode' => 'live', 'refnum' => '', 'transaction_key' => 'pi_9', 'card_brand' => 'Visa', 'card_last4' => '4242'];
    self::assertSame(['text' => 'Ship', 'value' => 'ship'], Grid::cell($this->column($columns, 'f:pickup'), $entry, $inputs, 'd', $payment));
    self::assertSame('Dana', Grid::cell($this->column($columns, 'f:name:first'), $entry, $inputs, 'd', $payment)['text']);
    self::assertSame('74.97', Grid::cell($this->column($columns, 'amount'), $entry, $inputs, 'd', $payment)['text']);
    self::assertSame('pi_9', Grid::cell($this->column($columns, 'transaction'), $entry, $inputs, 'd', $payment)['text']);
    self::assertSame('Visa •••• 4242', Grid::cell($this->column($columns, 'card'), $entry, $inputs, 'd', $payment)['text']);
  }

  public function testSettingsNeedALinkAndPasswordToBeOn(): void {
    self::assertFalse(FormSettings::normalize(['sheet' => ['enabled' => TRUE]])['sheet']['enabled']);
    $on = FormSettings::normalize(['sheet' => ['enabled' => TRUE, 'token' => str_repeat('ab', 16), 'password_hash' => '$P$Bxyz']])['sheet'];
    self::assertTrue($on['enabled']);
    self::assertTrue($on['editable']);
    self::assertSame('', FormSettings::normalize(['sheet' => ['token' => 'nope']])['sheet']['token']);
  }

  public function testXlsxSheetIsInlineTextAndNumbers(): void {
    $xml = Xlsx::sheet(['Entry', 'Note'], [[12, '=HYPERLINK("x")'], [13, "a & b\x01"]]);
    self::assertStringContainsString('<pane ySplit="1"', $xml);
    self::assertStringContainsString('<c r="A2"><v>12</v></c>', $xml);
    self::assertStringContainsString('<c r="B2" t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(&quot;x&quot;)</t></is></c>', $xml);
    self::assertStringContainsString('a &amp; b<', $xml);
    self::assertStringNotContainsString('<f>', $xml);
    self::assertSame('AB', Xlsx::column(27));
    if (Xlsx::available()) {
      $file = tempnam(sys_get_temp_dir(), 'efx');
      Xlsx::write($file, 'Book: Order', ['A'], [['x']]);
      $zip = new \ZipArchive();
      self::assertTrue($zip->open($file) === TRUE);
      self::assertStringContainsString('name="Book  Order"', $zip->getFromName('xl/workbook.xml'));
      $zip->close();
      unlink($file);
    }
  }

}
