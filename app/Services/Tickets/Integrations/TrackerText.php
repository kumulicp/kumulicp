<?php

namespace App\Services\Tickets\Integrations;

/**
 * Makes customer-written text safe to post into a tracker with our own
 * credentials. A customer must not be able to:
 *   - notify arbitrary people on the tracker by writing @name
 *   - run GitLab quick actions (a line starting with /close, /assign, /label...)
 *     with the integration's token
 * Both are defused with an invisible character; code blocks are left alone
 * because neither is interpreted there.
 */
final class TrackerText
{
    private const ZERO_WIDTH_SPACE = "\u{200B}";

    public static function neutralize(string $markdown): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $fence = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s{0,3}(```+|~~~+)/', $line, $match)) {
                $marker = $match[1][0];
                if ($fence === null) {
                    $fence = $marker;
                } elseif ($fence === $marker) {
                    $fence = null;
                }

                continue;
            }

            if ($fence !== null) {
                continue;
            }

            if (preg_match('#^\s*/#', $line)) {
                $line = self::ZERO_WIDTH_SPACE.$line;
            }

            // An @ that starts a word is a mention; the @ inside an email address is not
            $lines[$index] = preg_replace('/(?<![A-Za-z0-9_.+\-\x{200B}])@(?=[A-Za-z0-9_])/u', '@'.self::ZERO_WIDTH_SPACE, $line) ?? $line;
        }

        return implode("\n", $lines);
    }

    /**
     * A single-line title: no line breaks or control characters, bounded length.
     */
    public static function title(string $reference, string $subject): string
    {
        $subject = preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $subject) ?? $subject;
        $title = trim('['.$reference.'] '.trim($subject));

        return mb_strlen($title) > 200 ? mb_substr($title, 0, 199).'…' : $title;
    }
}
