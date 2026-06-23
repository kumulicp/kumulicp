<?php

namespace App\Support\Security\Parsers;

use InvalidArgumentException;

class ParserFactory
{
    public const MAP = [
        'kube-hunter' => KubeHunterParser::class,
        'kube-bench' => KubeBenchParser::class,
        'kubescape' => KubescapeParser::class,
        'trivy' => TrivyParser::class,
        'polaris' => PolarisParser::class,
        'nuclei' => NucleiParser::class,
    ];

    public static function make(string $tool): Parser
    {
        if (! array_key_exists($tool, self::MAP)) {
            throw new InvalidArgumentException("No parser registered for security tool [{$tool}]");
        }

        $class = self::MAP[$tool];

        return new $class;
    }
}
