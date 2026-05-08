<?php

namespace App\Services;

class CustomCipherService
{
    private static $map = [
        'a' => '@', 'b' => '$', 'c' => '*', 'd' => '&', 'e' => '!', 'f' => '#',
        'g' => '1', 'h' => '2', 'i' => '3', 'j' => '4', 'k' => '5', 'l' => '6',
        'm' => '7', 'n' => '8', 'o' => '9', 'p' => '0', 'q' => 'q', 'r' => 'w',
        's' => 'x', 't' => 'y', 'u' => 'z', 'v' => 'v', 'w' => 'u', 'x' => 't',
        'y' => 's', 'z' => 'r',
        ' ' => '_'
    ];

    public static function encryptData(string $text): string
    {
        $text = strtolower($text);
        return strtr($text, self::$map);
    }

    public static function decryptData(string $text): string
    {
        $reverseMap = array_flip(self::$map);
        return strtr($text, $reverseMap);
    }
}
