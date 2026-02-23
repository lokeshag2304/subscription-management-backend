<?php
use App\WebSockets\SocketServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Http\HttpServer;
use React\Socket\Server as Reactor;
use React\Socket\SecureServer;
use Ratchet\Server\IoServer;

require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$webSocket = new SocketServer();

$reactSocket = new Reactor('0.0.0.0:8089', $loop);

// Secure the connection with SSL
$secureWebSocket = new SecureServer($reactSocket, $loop, [
    'local_cert' => '/etc/ssl/certs/ticketportalbackend.testingscrew.com.crt', // <-- change this
    'local_pk' => '/etc/ssl/private/ticketportalbackend.testingscrew.com.key',
    'allow_self_signed' => true,  // for testing
    'verify_peer' => false        // for testing
]);

$server = new IoServer(
    new HttpServer(
        new WsServer($webSocket)
    ),
    $secureWebSocket,
    $loop
);



echo "Secure WebSocket server started on wss://ticketportalbackend.testingscrew.com:8089\n";

$loop->run();
