<?php
namespace App\Helpers;

class WebSocketHelper
{
    public static function send($message)
    {
        $host = env('WEBSOCKET_HOST', '127.0.0.1');
        $port = env('WEBSOCKET_PORT', 8080);

        try {
            $socket = fsockopen($host, $port, $errno, $errstr, 2);
            if (!$socket) {
                throw new \Exception("WebSocket connection failed: $errstr ($errno)");
            }

            fwrite($socket, $message);
            fclose($socket);
        } catch (\Exception $e) {
            \Log::error("WebSocket Error: " . $e->getMessage());
        }
    }
}
