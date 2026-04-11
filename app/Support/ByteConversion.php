<?php

namespace App\Support;

class ByteConversion
{
    private $unit;

    private $bytes;

    private $newUnit;

    private $newBytes;

    public function __invoke($bytes, $from, $to, $show = 'all')
    {
        $this->unit = $to;
        $this->bytes = $bytes;
        $function = $from.'_'.$to;
        $this->$function();
        //         $this->convert();
        if ($show == 'all') {
            return round($this->newBytes, 2).' '.$this->newUnit;
        } elseif ($show == 'byte') {
            return round($this->newBytes, 2);
        } elseif ($show == 'unit') {
            return $this->newUnit;
        }
    }

    private function convert()
    {
        if ($this->bytes >= 1024 && $this->bytes < 1024 * 1024) {
            if ($this->unit == 'mb') {
                $this->mb_gb();
            } elseif ($this->unit == 'gb') {
                $this->gb_tb();
            } elseif ($this->unit == 'kb') {
                $this->kb_mb();
            }
        } elseif ($this->bytes >= 1024 * 1024 && $this->bytes < 1024 * 1024 * 1024) {
            if ($this->unit == 'mb') {
                $this->mb_tb();
            } elseif ($this->unit == 'kb') {
                $this->kb_gb();
            } else {
                $this->keep();
            }
        } elseif ($this->bytes >= 1024 * 1024 * 1024) {
            if ($this->unit == 'kb') {
                $this->kb_tb();
            } else {
                $this->keep();
            }
        } else {
            $this->keep();
        }

        $this->newBytes = round($this->newBytes, 2);
    }

    private function keep()
    {
        $this->newUnit = strtoupper($this->unit);
        $this->newBytes = $this->bytes;
    }

    private function kb_mb()
    {
        $this->newUnit = 'MB';
        $this->newBytes = $this->bytes / 1024;
    }

    private function kb_gb()
    {
        $this->newUnit = 'GB';
        $this->newBytes = $this->bytes / 1024 / 1024;
    }

    private function kb_tb()
    {
        $this->newUnit = 'TB';
        $this->newBytes = $this->bytes / 1024 / 1024 / 1024;
    }

    private function mb_gb()
    {
        $this->newUnit = 'GB';
        $this->newBytes = $this->bytes / 1024;
    }

    private function gb_tb()
    {
        $this->newUnit = 'TB';
        $newBytes = $this->bytes / 1024;
    }

    private function mb_tb()
    {
        $this->newUnit = 'TB';
        $this->newBytes = $this->bytes / 1024 / 1024;
    }

    private function gb_b()
    {
        $this->newUnit = 'b';
        $this->newBytes = $this->bytes * pow(1024, 3);
    }

    private function b_gb()
    {
        $this->newUnit = 'GB';
        $this->newBytes = $this->bytes / pow(1024, 3);
    }

    private function b_mb()
    {
        $this->newUnit = 'MB';
        $this->newBytes = $this->bytes / pow(1024, 2);
    }

    private function gb_mb()
    {
        $this->newUnit = 'MB';
        $this->newBytes = $this->bytes * pow(1024, 1);
    }
}
