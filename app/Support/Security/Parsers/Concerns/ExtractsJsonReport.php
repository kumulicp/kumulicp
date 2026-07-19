<?php

namespace App\Support\Security\Parsers\Concerns;

use Illuminate\Support\Facades\Log;

trait ExtractsJsonReport
{
    /**
     * `kubectl logs` merges stdout/stderr, so raw_output has the tool's own
     * log lines (and sometimes a pretty-printer summary) before the actual
     * report, which isn't itself valid JSON. Find the last complete top-level
     * JSON object in the text and decode that.
     */
    private function extractJsonReport(string $raw_output): ?array
    {
        $span = $this->findLastJsonObjectSpan($raw_output);

        if ($span === null) {
            Log::warning(static::class.' could not find a JSON report in the scan output', [
                'raw_output_length' => strlen($raw_output),
            ]);

            return null;
        }

        $decoded = json_decode($span, true);

        if (! is_array($decoded)) {
            Log::warning(static::class.' found a JSON object but could not decode it', [
                'raw_output_length' => strlen($raw_output),
                'json_error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * Scans for the last complete top-level `{...}` object in $text,
     * repairing any bare control characters (raw newlines/carriage returns)
     * found inside string literals along the way.
     *
     * A tool's report can be tens of megabytes on a single logical line for a
     * large cluster. Something in the Kubernetes/Rancher log pipeline can
     * split very long lines by inserting literal newline bytes, which is
     * invisible to a plain line-based split but makes the JSON invalid once
     * those bytes land inside a string value - this repairs that in the same
     * pass as finding the object's boundaries.
     */
    private function findLastJsonObjectSpan(string $text): ?string
    {
        $length = strlen($text);
        $best = null;
        $i = 0;

        while ($i < $length) {
            if ($text[$i] !== '{') {
                $i++;

                continue;
            }

            $depth = 1;
            $in_string = false;
            $buffer = '{';
            $j = $i + 1;

            while ($j < $length && $depth > 0) {
                $char = $text[$j];

                if ($in_string) {
                    if ($char === '\\' && $j + 1 < $length) {
                        $buffer .= $char.$text[$j + 1];
                        $j += 2;

                        continue;
                    }

                    if ($char === '"') {
                        $in_string = false;
                        $buffer .= $char;
                        $j++;

                        continue;
                    }

                    if ($char === "\n" || $char === "\r") {
                        $buffer .= $char === "\n" ? '\\n' : '\\r';
                        $j++;

                        continue;
                    }

                    $buffer .= $char;
                    $j++;

                    continue;
                }

                if ($char === '"') {
                    $in_string = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }

                $buffer .= $char;
                $j++;
            }

            if ($depth === 0) {
                $best = $buffer;
                $i = $j;
            } else {
                $i++;
            }
        }

        return $best;
    }
}
