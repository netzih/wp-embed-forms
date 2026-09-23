<?php

namespace EmbedForms\Tests;

use EmbedForms\Schema\Conditions;
use EmbedForms\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class ConditionsTest extends TestCase {

  private function schema(): array {
    return FormSchema::normalize(['fields' => [
      ['id' => 'member', 'type' => 'radio', 'options' => ['Yes', 'No']],
      ['id' => 'number', 'type' => 'text', 'conditions' => ['action' => 'show', 'rules' => [['field' => 'member', 'op' => 'is', 'value' => 'yes']]]],
      ['id' => 'why', 'type' => 'text', 'conditions' => ['action' => 'show', 'rules' => [['field' => 'number', 'op' => 'notempty']]]],
      ['id' => 'extras', 'type' => 'section', 'conditions' => ['action' => 'hide', 'rules' => [['field' => 'member', 'op' => 'is', 'value' => 'No']]]],
      ['id' => 'shirt', 'type' => 'text'],
      ['id' => 'p2', 'type' => 'page'],
      ['id' => 'later', 'type' => 'text'],
    ]]);
  }

  public function testShowRuleIsCaseInsensitive(): void {
    $v = Conditions::visibility($this->schema(), ['member' => 'Yes']);
    self::assertTrue($v['number']);
  }

  public function testHiddenFieldCountsAsEmptyForChains(): void {
    // "number" has a stale answer but is hidden, so "why" must hide too.
    $v = Conditions::visibility($this->schema(), ['member' => 'No', 'number' => '123']);
    self::assertFalse($v['number']);
    self::assertFalse($v['why']);
  }

  public function testSectionHidesFieldsUntilNextPage(): void {
    $v = Conditions::visibility($this->schema(), ['member' => 'No']);
    self::assertFalse($v['extras']);
    self::assertFalse($v['shirt']);
    self::assertTrue($v['p2']);
    self::assertTrue($v['later']);
  }

  public function testOperators(): void {
    self::assertTrue(Conditions::rule(['op' => 'contains', 'value' => 'ell'], 'Hello'));
    self::assertTrue(Conditions::rule(['op' => 'notcontains', 'value' => 'xyz'], 'Hello'));
    self::assertTrue(Conditions::rule(['op' => 'is', 'value' => 'b'], ['a', 'B']));
    self::assertTrue(Conditions::rule(['op' => 'isnot', 'value' => 'c'], ['a', 'b']));
    self::assertTrue(Conditions::rule(['op' => 'gt', 'value' => '10'], '10.5'));
    self::assertFalse(Conditions::rule(['op' => 'gt', 'value' => '10'], 'abc'));
    self::assertTrue(Conditions::rule(['op' => 'lt', 'value' => '5'], '4'));
    self::assertTrue(Conditions::rule(['op' => 'empty', 'value' => ''], []));
    self::assertTrue(Conditions::rule(['op' => 'empty', 'value' => ''], ['first' => '', 'last' => ' ']));
    self::assertTrue(Conditions::rule(['op' => 'contains', 'value' => 'smith'], ['first' => 'Jo', 'last' => 'Smith']));
    self::assertTrue(Conditions::rule(['op' => 'is', 'value' => ''], NULL));
  }

  public function testAnyMatch(): void {
    $c = ['action' => 'show', 'match' => 'any', 'rules' => [['field' => 'a', 'op' => 'is', 'value' => '1'], ['field' => 'b', 'op' => 'is', 'value' => '2']]];
    self::assertTrue(Conditions::applies($c, ['a' => '0', 'b' => '2']));
    self::assertFalse(Conditions::applies($c, ['a' => '0', 'b' => '0']));
  }

}
