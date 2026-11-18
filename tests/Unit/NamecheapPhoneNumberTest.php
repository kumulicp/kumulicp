<?php

use App\Integrations\Registrars\Namecheap\Helpers\PhoneNumber;

it('formats a phone the way Namecheap wants it', function (?string $code, ?string $number, string $expected) {
    expect(PhoneNumber::format($code, $number))->toBe($expected);
})->with([
    'plain' => ['+1', '604 555-0100', '+1.6045550100'],
    'brackets and dots' => ['+1', '(604) 555-0100', '+1.6045550100'],
    'dotted' => ['+1', '604.555.0100', '+1.6045550100'],
    'leading 1' => ['+1', '1-604-555-0100', '+1.6045550100'],
    'international with a plus' => ['+1', '+1 604 555 0100', '+1.6045550100'],
    'extension dropped' => ['+1', '604 555 0100 ext. 12', '+1.6045550100'],
    'code without a plus' => ['44', '7700 900123', '+44.7700900123'],
    'code with spaces around it' => [' +44 ', '7700 900123', '+44.7700900123'],
    'trunk zero dropped' => ['+44', '07700 900123', '+44.7700900123'],
    'international with a plus (UK)' => ['+44', '+44 7700 900123', '+44.7700900123'],
    'international with 00' => ['+44', '0044 7700 900123', '+44.7700900123'],
    'Italy keeps its zero' => ['+39', '06 1234 5678', '+39.0612345678'],
    'NANP area part goes to the number' => ['+1 684', '258 1234', '+1.6842581234'],
    'NANP area part not doubled' => ['+1 684', '684 258 1234', '+1.6842581234'],
    'NANP full number is left alone' => ['+1 684', '604 555 0100', '+1.6045550100'],
    'Abkhazia' => ['+7 840', '123 4567', '+7.8401234567'],
    'no number' => ['+1', '', ''],
    'no digits' => ['+1', 'n/a', ''],
    'nothing' => [null, null, ''],
]);
