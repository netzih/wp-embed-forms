<?php

namespace EmbedForms\Tests;

use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

  private function schema(): array {
    return FormSchema::normalize(['fields' => [
      ['id' => 'name', 'type' => 'name', 'required' => TRUE, 'parts' => ['first', 'middle', 'last']],
      ['id' => 'email', 'type' => 'email', 'required' => TRUE],
      ['id' => 'phone', 'type' => 'phone'],
      ['id' => 'age', 'type' => 'number', 'min' => 18, 'max' => 120],
      ['id' => 'color', 'type' => 'select', 'options' => ['Red', 'Blue']],
      ['id' => 'tags', 'type' => 'checkbox', 'options' => ['A', 'B']],
      ['id' => 'day', 'type' => 'date'],
      ['id' => 'member', 'type' => 'radio', 'options' => ['Yes', 'No']],
      ['id' => 'number', 'type' => 'text', 'required' => TRUE, 'conditions' => ['rules' => [['field' => 'member', 'op' => 'is', 'value' => 'Yes']]]],
      ['id' => 'notes', 'type' => 'textarea'],
    ]]);
  }

  public function testValidSubmissionIsCleaned(): void {
    $result = Validator::validate($this->schema(), [
      'name' => ['first' => ' Ada ', 'last' => 'Lovelace', 'evil' => 'x'],
      'email' => 'ada@example.org',
      'phone' => '+1 (804) 555-0100',
      'age' => '36',
      'color' => 'Blue',
      'tags' => ['A', 'B', 'A'],
      'day' => '2026-09-23',
      'member' => 'No',
      'number' => 'should be dropped',
      'notes' => "Line 1\r\nLine <b>2</b>",
      'unknown' => 'dropped',
    ]);
    self::assertSame([], $result['errors']);
    self::assertSame(['first' => 'Ada', 'middle' => '', 'last' => 'Lovelace'], $result['values']['name']);
    self::assertSame(['A', 'B'], $result['values']['tags']);
    self::assertSame("Line 1\nLine 2", $result['values']['notes']);
    self::assertArrayNotHasKey('number', $result['values']);
    self::assertArrayNotHasKey('unknown', $result['values']);
  }

  public function testErrors(): void {
    $result = Validator::validate($this->schema(), [
      'name' => ['first' => 'Ada'],
      'email' => 'not-an-email',
      'phone' => '12',
      'age' => '12',
      'color' => 'Green',
      'tags' => ['C'],
      'day' => '2026-02-30',
      'member' => 'Yes',
      'number' => '',
    ]);
    self::assertSame(['name', 'email', 'phone', 'age', 'color', 'tags', 'day', 'number'], array_keys($result['errors']));
  }

  public function testNonScalarInputIsEmpty(): void {
    $result = Validator::validate($this->schema(), ['email' => ['x'], 'name' => 'Ada']);
    self::assertArrayHasKey('email', $result['errors']);
    self::assertArrayHasKey('name', $result['errors']);
  }

}
