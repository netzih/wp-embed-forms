<?php

namespace EmbedForms\Tests;

use EmbedForms\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class FormSchemaTest extends TestCase {

  public function testNormalizesFieldsAndDropsUnknownKeys(): void {
    $schema = FormSchema::normalize([
      'fields' => [
        ['id' => 'Email Address', 'type' => 'email', 'label' => ' Email ', 'required' => 1, 'evil' => 'x'],
        ['type' => 'select', 'label' => 'Color', 'options' => ['Red', ['label' => 'Blue', 'value' => 'b'], ['label' => '']]],
      ],
    ]);
    self::assertSame('emailaddress', $schema['fields'][0]['id']);
    self::assertSame('Email', $schema['fields'][0]['label']);
    self::assertTrue($schema['fields'][0]['required']);
    self::assertArrayNotHasKey('evil', $schema['fields'][0]);
    self::assertSame('select_1', $schema['fields'][1]['id']);
    self::assertSame([['label' => 'Red', 'value' => 'Red'], ['label' => 'Blue', 'value' => 'b']], $schema['fields'][1]['options']);
  }

  public function testAcceptsJson(): void {
    $schema = FormSchema::normalize('{"fields":[{"id":"a","type":"text","label":"A"}]}');
    self::assertSame('a', $schema['fields'][0]['id']);
  }

  public function testRejectsUnknownTypesDuplicateIdsAndBadJson(): void {
    foreach ([
      '{"fields":[{"type":"rocket"}]}',
      '{"fields":[{"id":"a","type":"text"},{"id":"a","type":"email"}]}',
      '{not json',
      '{"fields":{"a":1}}',
    ] as $bad) {
      try {
        FormSchema::normalize($bad);
        self::fail('Accepted ' . $bad);
      }
      catch (\InvalidArgumentException $e) {
        self::assertNotSame('', $e->getMessage());
      }
    }
  }

  public function testConditionsKeepOnlyRulesOnExistingInputs(): void {
    $schema = FormSchema::normalize(['fields' => [
      ['id' => 'a', 'type' => 'radio', 'options' => ['Yes', 'No']],
      ['id' => 'h', 'type' => 'html', 'content' => '<p>x</p>'],
      ['id' => 'b', 'type' => 'text', 'conditions' => ['action' => 'show', 'match' => 'all', 'rules' => [
        ['field' => 'a', 'op' => 'is', 'value' => 'Yes'],
        ['field' => 'h', 'op' => 'is', 'value' => 'x'],
        ['field' => 'missing', 'op' => 'is', 'value' => 'x'],
        ['field' => 'b', 'op' => 'is', 'value' => 'self'],
        ['field' => 'a', 'op' => 'explode', 'value' => ''],
      ]]],
      ['id' => 'c', 'type' => 'text', 'conditions' => ['rules' => [['field' => 'missing', 'op' => 'is']]]],
    ]]);
    self::assertSame([['field' => 'a', 'op' => 'is', 'value' => 'Yes']], $schema['fields'][2]['conditions']['rules']);
    self::assertArrayNotHasKey('conditions', $schema['fields'][3]);
  }

  public function testNamePartsDefaultAndFilter(): void {
    $schema = FormSchema::normalize(['fields' => [
      ['id' => 'n', 'type' => 'name'],
      ['id' => 'm', 'type' => 'name', 'parts' => ['prefix', 'first', 'bogus', 'last']],
    ]]);
    self::assertSame(['first', 'last'], $schema['fields'][0]['parts']);
    self::assertSame(['prefix', 'first', 'last'], $schema['fields'][1]['parts']);
  }

}
