<?php

use App\Services\Tickets\DurationParser;
use App\Services\Tickets\Integrations\HtmlToMarkdown;
use App\Services\Tickets\Integrations\IpGuard;
use App\Services\Tickets\Integrations\TrackerText;

// ---- IP guard

it('treats public addresses as public', function (string $ip) {
    expect(IpGuard::isPublic($ip))->toBeTrue();
})->with(['93.184.216.34', '8.8.8.8', '140.82.112.3', '2606:4700:4700::1111', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1']);

it('refuses private, internal and special addresses', function (string $ip) {
    expect(IpGuard::isPublic($ip))->toBeFalse();
})->with([
    'loopback' => '127.0.0.1', 'loopback range' => '127.1.2.3', 'private 10' => '10.0.0.1', 'private 172' => '172.16.0.1', 'private 172 end' => '172.31.255.255',
    'private 192' => '192.168.1.1', 'cloud metadata' => '169.254.169.254', 'link local' => '169.254.0.1', 'this network' => '0.0.0.0',
    'carrier nat' => '100.64.0.1', 'carrier nat end' => '100.127.255.255', 'ietf' => '192.0.0.1', 'benchmark' => '198.18.0.1', 'multicast' => '224.0.0.1',
    'reserved' => '240.0.0.1', 'broadcast' => '255.255.255.255', 'v6 loopback' => '::1', 'v6 unspecified' => '::', 'v6 link local' => 'fe80::1',
    'v6 unique local' => 'fc00::1', 'v6 unique local fd' => 'fd12:3456::1', 'v6 multicast' => 'ff02::1', 'v6 documentation' => '2001:db8::1',
    'mapped loopback' => '::ffff:127.0.0.1', 'mapped private' => '::ffff:10.0.0.1', 'mapped metadata' => '::ffff:169.254.169.254',
    'nat64 private' => '64:ff9b::a00:1', 'compatible private' => '::10.0.0.1', 'not an address' => 'not-an-ip', 'empty' => '',
]);

// ---- durations

it('reads the ways people write a length of time', function (string $input, ?int $minutes) {
    expect(DurationParser::parse($input))->toBe($minutes);
})->with([
    ['90', 90], ['90m', 90], ['90 min', 90], ['1h', 60], ['1h30', 90], ['1h 30m', 90], ['1.5h', 90], ['1,5h', 90], ['1:30', 90],
    ['2 hours 15 minutes', 135], ['45m', 45], [' 15 ', 15], ['0', 0],
    ['abc', null], ['', null], ['1h30x', null], ['1:75', null], ['-5', null], ['h', null],
]);

it('writes durations back out', function () {
    expect(DurationParser::format(90))->toBe('1h 30m')
        ->and(DurationParser::format(60))->toBe('1h')
        ->and(DurationParser::format(5))->toBe('5m')
        ->and(DurationParser::compact(135))->toBe('2h15m');
});

// ---- markdown

it('turns ticket html into markdown', function (string $html, string $markdown) {
    expect((new HtmlToMarkdown)->convert($html))->toBe($markdown);
})->with([
    'paragraphs' => ['<p>Hello</p><p>World</p>', "Hello\n\nWorld"],
    'emphasis' => ['<p><strong>b</strong> <em>i</em> <s>s</s></p>', '**b** *i* ~~s~~'],
    'link' => ['<p><a href="https://example.com/a?b=1">site</a></p>', '[site](https://example.com/a?b=1)'],
    'unsafe link scheme is dropped' => ['<p><a href="javascript:alert(1)">x</a></p>', 'x'],
    'lists' => ['<ul><li>a</li><li>b</li></ul><ol><li>one</li><li>two</li></ol>', "- a\n- b\n\n1. one\n2. two"],
    'nested list' => ['<ul><li>a<ul><li>b</li></ul></li></ul>', "- a\n  - b"],
    'quote' => ['<blockquote><p>q1</p><p>q2</p></blockquote>', "> q1\n> \n> q2"],
    'code block is kept literal' => ["<pre><code>\$ ls -la\na_b *c*</code></pre>", "```\n\$ ls -la\na_b *c*\n```"],
    'fence inside code' => ["<pre>a\n```\nb</pre>", "~~~\na\n```\nb\n~~~"],
    'inline code' => ['<p>run <code>ls -l</code></p>', 'run `ls -l`'],
    'headings' => ['<h3>Title</h3><h4>Sub</h4>', "### Title\n\n#### Sub"],
    'images are not fetched' => ['<p>see <img src="/support/tickets/attachments/5" alt="x"></p>', 'see [image]'],
    'plain text cannot become formatting' => ['<p>snake_case *star* [x] &lt;b&gt;</p>', 'snake\_case \*star\* \[x\] \<b\>'],
    'line break' => ['<p>a<br>b</p>', "a  \nb"],
    'empty' => ['  ', ''],
    'unicode' => ['<p>Café — 日本語</p>', 'Café — 日本語'],
]);

// ---- tracker-safe text

it('stops customers running slash commands with our token', function () {
    $z = "\u{200B}";

    expect(TrackerText::neutralize("hello\n/close\nbye"))->toBe("hello\n{$z}/close\nbye")
        ->and(TrackerText::neutralize('  /assign @me'))->toBe("{$z}  /assign @{$z}me")
        ->and(TrackerText::neutralize("/label ~bug\n/estimate 1h"))->toBe("{$z}/label ~bug\n{$z}/estimate 1h");
});

it('stops customers mentioning people on the tracker', function () {
    $z = "\u{200B}";

    expect(TrackerText::neutralize('ping @torvalds please'))->toBe("ping @{$z}torvalds please")
        ->and(TrackerText::neutralize('@org/team look'))->toBe("@{$z}org/team look");
});

it('leaves ordinary text, email addresses and code blocks alone', function () {
    $z = "\u{200B}";

    expect(TrackerText::neutralize('mail bob@example.com now'))->toBe('mail bob@example.com now')
        ->and(TrackerText::neutralize('see /var/log/syslog now'))->toBe('see /var/log/syslog now')
        ->and(TrackerText::neutralize("before\n```\n/close\n@name\n```\nafter @name"))->toBe("before\n```\n/close\n@name\n```\nafter @{$z}name")
        ->and(TrackerText::neutralize("~~~\n/close\n~~~"))->toBe("~~~\n/close\n~~~");
});

it('makes a single-line title of bounded length', function () {
    expect(TrackerText::title('KCP-5', "Broken\nlogin\r\n/close"))->toBe('[KCP-5] Broken login /close')
        ->and(mb_strlen(TrackerText::title('KCP-5', str_repeat('a', 500))))->toBe(200);
});
