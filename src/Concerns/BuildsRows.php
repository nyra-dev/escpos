<?php

namespace Nyra\EscPos\Concerns;

trait BuildsRows
{
    protected function buildRow(array $cols, array $widths, string $separator = ' '): string
    {
        $out = '';
        $count = min(count($cols), count($widths));
        for ($i = 0; $i < $count; $i++) {
            $w = max(1, (int)$widths[$i]);
            $cell = (string)$cols[$i];
            $cell = $this->safeSubstr($cell, 0, $w);

            if (preg_match('/^\s*[-+]?\d+([.,]\d+)?\s*$/u', $cell)) {
                $cell = str_pad($cell, $w, ' ', STR_PAD_LEFT);
            } else {
                $cell = str_pad($cell, $w, ' ', STR_PAD_RIGHT);
            }

            $out .= $cell;
            if ($i < $count - 1) $out .= $separator;
        }
        return rtrim($out);
    }

    private function safeSubstr(string $s, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, $start, $length, 'UTF-8');
        }
        return substr($s, $start, $length);
    }
}
