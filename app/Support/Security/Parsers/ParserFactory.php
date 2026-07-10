<?php

namespace App\Support\Security\Parsers;

use App\Support\Facades\SecurityTool;
use InvalidArgumentException;

class ParserFactory
{
    public static function make(string $tool): Parser
    {
        $profile = SecurityTool::profile($tool);

        if (! $profile) {
            throw new InvalidArgumentException("No parser registered for security tool [{$tool}]");
        }

        return $profile->parser();
    }
}
