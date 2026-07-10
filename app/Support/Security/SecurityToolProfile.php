<?php

namespace App\Support\Security;

use App\Support\Security\Parsers\Parser;

class SecurityToolProfile
{
    protected $name;

    protected $image;

    protected $command = [];

    protected $parser;

    public function name()
    {
        return $this->name;
    }

    public function image()
    {
        return $this->image;
    }

    public function command()
    {
        return $this->command;
    }

    public function parser(): Parser
    {
        return new $this->parser;
    }
}
