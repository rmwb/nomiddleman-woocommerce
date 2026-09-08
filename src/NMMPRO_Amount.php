<?php
if (!defined('ABSPATH')) { exit; }

/** Exact decimal boundaries and unsigned atomic-unit arithmetic, without extensions. */
class NMMPRO_Amount {
    const MAX_DIGITS = 512;

    private static function parts($value) {
        if (is_float($value)) {
            if (!is_finite($value)) { throw new InvalidArgumentException('Non-finite amount'); }
            // Only external rates/legacy adapter values may arrive as floats.
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || strlen($value) > self::MAX_DIGITS ||
            !preg_match('/^\+?(\d+(?:\.\d*)?|\.\d+)(?:[eE]([+-]?\d{1,3}))?$/D', $value, $m)) {
            throw new InvalidArgumentException('Invalid non-negative amount');
        }
        $pieces = explode('.', $m[1], 2);
        $fraction = isset($pieces[1]) ? $pieces[1] : '';
        $scale = strlen($fraction) - (isset($m[2]) ? (int) $m[2] : 0);
        $digits = ltrim($pieces[0] . $fraction, '0');
        if ($digits === '') { return array('0', 0); }
        if (abs($scale) + strlen($digits) > self::MAX_DIGITS) { throw new InvalidArgumentException('Amount too long'); }
        if ($scale < 0) { $digits .= str_repeat('0', -$scale); $scale = 0; }
        return array($digits, $scale);
    }

    private static function precision($precision) {
        if (!is_int($precision) || $precision < 0 || $precision > 36) {
            throw new InvalidArgumentException('Invalid currency precision');
        }
    }

    private static function integer($value) {
        if (is_int($value)) { $value = (string) $value; }
        if (!is_string($value) || strlen($value) > self::MAX_DIGITS || !preg_match('/^\d+$/D', $value)) {
            throw new InvalidArgumentException('Invalid atomic units');
        }
        return ltrim($value, '0') ?: '0';
    }

    public static function normalize($value) {
        list($digits, $scale) = self::parts($value);
        if (!$scale) { return $digits; }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        return rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.');
    }

    public static function to_units($value, $precision) {
        self::precision($precision);
        list($digits, $scale) = self::parts($value);
        if ($scale <= $precision) { return self::integer($digits . str_repeat('0', $precision - $scale)); }
        $length = strlen($digits) - ($scale - $precision);
        return $length > 0 ? self::integer(substr($digits, 0, $length)) : '0';
    }

    public static function from_units($value, $precision) {
        self::precision($precision);
        $digits = self::integer($value);
        if (!$precision) { return $digits; }
        $digits = str_pad($digits, $precision + 1, '0', STR_PAD_LEFT);
        return rtrim(rtrim(substr($digits, 0, -$precision) . '.' . substr($digits, -$precision), '0'), '.');
    }

    public static function compare($a, $b) {
        $a = self::integer($a); $b = self::integer($b);
        return strlen($a) === strlen($b) ? (strcmp($a, $b) <=> 0) : (strlen($a) <=> strlen($b));
    }

    public static function add($a, $b) {
        $a = self::integer($a); $b = self::integer($b);
        $i = strlen($a) - 1; $j = strlen($b) - 1; $carry = 0; $out = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $sum = ($i >= 0 ? (int) $a[$i--] : 0) + ($j >= 0 ? (int) $b[$j--] : 0) + $carry;
            $out = ($sum % 10) . $out; $carry = intdiv($sum, 10);
        }
        return self::integer($out);
    }

    private static function subtract($a, $b) {
        if (self::compare($a, $b) < 0) { throw new InvalidArgumentException('Negative result'); }
        $i = strlen($a) - 1; $j = strlen($b) - 1; $borrow = 0; $out = '';
        while ($i >= 0) {
            $n = (int) $a[$i--] - $borrow - ($j >= 0 ? (int) $b[$j--] : 0);
            $borrow = $n < 0 ? 1 : 0; $out = ($n + 10 * $borrow) . $out;
        }
        return self::integer($out);
    }

    public static function multiply($a, $b) {
        $a = self::integer($a); $b = self::integer($b);
        if (strlen($a) + strlen($b) > self::MAX_DIGITS) { throw new InvalidArgumentException('Product too long'); }
        $out = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) { $out[$i + $j + 1] += (int) $a[$i] * (int) $b[$j]; }
        }
        for ($i = count($out) - 1; $i > 0; $i--) { $out[$i - 1] += intdiv($out[$i], 10); $out[$i] %= 10; }
        return self::integer(implode('', $out));
    }

    private static function divmod($a, $b) {
        $a = self::integer($a); $b = self::integer($b);
        if ($b === '0') { throw new InvalidArgumentException('Zero rate/divisor'); }
        $quotient = ''; $remainder = '0';
        for ($i = 0; $i < strlen($a); $i++) {
            $remainder = self::integer($remainder . $a[$i]); $digit = 0;
            while (self::compare($remainder, $b) >= 0) { $remainder = self::subtract($remainder, $b); $digit++; }
            $quotient .= $digit;
        }
        return array(self::integer($quotient), $remainder);
    }

    public static function divide($a, $b) { return self::divmod($a, $b)[0]; }

    public static function rounded($value, $precision) {
        self::precision($precision);
        list($digits, $scale) = self::parts($value);
        if ($scale <= $precision) { return self::from_units(self::to_units($value, $precision), $precision); }
        $divisor = '1' . str_repeat('0', $scale - $precision);
        list($units, $remainder) = self::divmod($digits, $divisor);
        if (self::compare(self::multiply($remainder, '2'), $divisor) >= 0) { $units = self::add($units, '1'); }
        return self::from_units($units, $precision);
    }

    public static function clears($received, $expected, $fraction) {
        $expected = self::integer($expected);
        if ($expected === '0') { return false; }
        list($n, $scale) = self::parts($fraction);
        $d = '1' . str_repeat('0', $scale);
        if ($n === '0' || self::compare($n, $d) > 0) { throw new InvalidArgumentException('Invalid payment threshold'); }
        return self::compare(self::multiply($received, $d), self::multiply($expected, $n)) >= 0;
    }

    /** Round once, after fiat * USD conversion / coin price * markup. */
    public static function quote($fiat, $usdRate, $coinRate, $markup, $precision) {
        self::precision($precision);
        list($a, $sa) = self::parts($fiat);
        list($b, $sb) = self::parts($usdRate);
        list($c, $sc) = self::parts($coinRate);
        $markup = (string) $markup;
        $negative = substr($markup, 0, 1) === '-';
        list($m, $sm) = self::parts($negative ? substr($markup, 1) : $markup);
        $hundred = '100' . str_repeat('0', $sm);
        $factor = $negative ? self::subtract($hundred, $m) : self::add($hundred, $m);
        $numerator = self::multiply(self::multiply($a, $b), $factor);
        $denominator = self::multiply($c, '100');
        $shift = $precision + $sc - $sa - $sb - $sm;
        if ($shift >= 0) { $numerator .= str_repeat('0', $shift); } else { $denominator .= str_repeat('0', -$shift); }
        list($units, $remainder) = self::divmod($numerator, $denominator);
        if (self::compare(self::multiply($remainder, '2'), $denominator) >= 0) { $units = self::add($units, '1'); }
        return self::from_units($units, $precision);
    }
}
