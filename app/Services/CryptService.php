<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Config;

class CryptService
{
    private static string $key = 'f49797bf6bafb4fac5830f764deabad0'; // Must be 32 chars
    private static string $iv = 'b6b6efef676e4973'; // Must be exactly 16 chars


    /**
     * Encrypt a given string using AES-256-CBC with a fixed IV.
     *
     * @param string $value
     * @return string
     */
    // public static function encryptData(string $value): string
    // {
    //     return base64_encode(openssl_encrypt($value, 'AES-256-CBC', self::$key, 0, self::$iv));
    // }
    public static function encryptData(?string $value): string
    {
        if (empty($value)) {
            return '';  // Return an empty string if the value is null or empty
        }

        return base64_encode(openssl_encrypt($value, 'AES-256-CBC', self::$key, 0, self::$iv));
    }

    /**
     * Decrypt a given string using AES-256-CBC with a fixed IV.
     *
     * @param string $value
     * @return string
     */
  public static function decryptData($value): string
    {
        if(empty($value)){
            return '';
        }
        if (!is_string($value)) {
            return $value;  // Return an empty string if the value is null, empty, or not a string
        }

        $decrypted = openssl_decrypt(base64_decode($value), 'AES-256-CBC', self::$key, 0, self::$iv);
        
        // If decryption fails, return the original value
        if ($decrypted === false) {
            return $value;
        }

        // Ensure the decrypted string is valid UTF-8 to prevent JSON encoding errors
        return mb_convert_encoding($decrypted, 'UTF-8', 'UTF-8');
    }


}