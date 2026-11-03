<?php

namespace App\Services\Tickets\Integrations;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns a ticket message into Markdown for a tracker. It only has to understand
 * what the ticket sanitiser lets through (paragraphs, bold, italic, lists, quotes,
 * code, links, two heading levels), so it is deliberately small. Anything it does
 * not know is reduced to its text.
 */
class HtmlToMarkdown
{
    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);

        if (! $body) {
            return '';
        }

        $markdown = $this->children($body);

        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown);
    }

    private function children(DOMNode $node, bool $literal = false): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $this->node($child, $literal);
        }

        return $out;
    }

    private function node(DOMNode $node, bool $literal): string
    {
        if ($node instanceof DOMText) {
            return $literal ? $node->data : $this->escape($node->data);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $inner = fn () => $this->children($node);

        switch (strtolower($node->tagName)) {
            case 'p':
            case 'div':
                $text = trim($inner());

                return $text === '' ? '' : $text."\n\n";

            case 'br':
                return "  \n";

            case 'strong':
            case 'b':
                return $this->wrap($inner(), '**');

            case 'em':
            case 'i':
                return $this->wrap($inner(), '*');

            case 's':
            case 'strike':
                return $this->wrap($inner(), '~~');

            case 'code':
                $text = $node->textContent;

                return $text === '' ? '' : '`'.str_replace('`', "'", $text).'`';

            case 'pre':
                $text = rtrim($node->textContent, "\n");
                $fence = str_contains($text, '```') ? '~~~' : '```';

                return $fence."\n".$text."\n".$fence."\n\n";

            case 'a':
                $text = trim($inner());
                $href = trim($node->getAttribute('href'));

                if ($text === '') {
                    return '';
                }

                return preg_match('#^(https?://|mailto:)#i', $href) ? '['.$text.']('.str_replace([' ', ')'], ['%20', '%29'], $href).')' : $text;

            case 'h3':
                return '### '.trim($inner())."\n\n";

            case 'h4':
                return '#### '.trim($inner())."\n\n";

            case 'ul':
            case 'ol':
                // A list inside a list item continues that item; any other list is its own block
                $nested = $node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'li';

                return $nested ? "\n".$this->list($node)."\n" : $this->list($node)."\n\n";

            case 'blockquote':
                $text = trim($inner());

                return $text === '' ? '' : preg_replace('/^/m', '> ', $text)."\n\n";

            case 'img':
                // The picture lives in the ticket, which the tracker cannot open
                return '[image]';

            default:
                return $inner();
        }
    }

    private function list(DOMElement $list): string
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $lines = [];
        $number = 1;

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $text = trim(preg_replace("/\n{2,}/", "\n", $this->children($item)) ?? '');
            $marker = $ordered ? $number++.'. ' : '- ';
            $indent = str_repeat(' ', strlen($marker));

            $lines[] = $marker.str_replace("\n", "\n".$indent, $text);
        }

        return implode("\n", $lines);
    }

    private function wrap(string $text, string $mark): string
    {
        $trimmed = trim($text);

        return $trimmed === '' ? '' : $mark.$trimmed.$mark;
    }

    /**
     * Stop plain text from being read as formatting.
     */
    private function escape(string $text): string
    {
        $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;

        return preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $text) ?? $text;
    }
}
