<?php
// https://nomadphp.com/blog/92/build-a-chat-system-with-php-sockets-and-w3c-web-sockets-apis
namespace App\Business\Console;

use BlueFission\Net\HTTP;
use BlueFission\Services\Service;

class SocketManager extends Service {

	private $host;
	private $port;
	private $null;

	public function __construct( )
    {
    	$this->host = 'localhost';
		$this->port = '9000';
		$this->null = NULL; 

		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($socket, 0, $port);
		socket_listen($socket);
		$clients = array($socket);

		parent::__construct();
	}

	public function run( )
	{
		while (true) {
			$changed = $clients;
			socket_select($changed, $null, $null, 0, 10);

			if (in_array($socket, $changed)) {
				$socket_new = socket_accept($socket);	$clients[] = $socket_new; 
				$header = socket_read($socket_new, 1024);
				$this->perform_handshaking($header, $socket_new, $host, $port); 
				socket_getpeername($socket_new, $ip);	$response = $this->mask(HTTP::jsonEncode(['type'=>'system', 'message'=>$ip.' connected']));
				$this->send_message($response); 
				$found_socket = array_search($socket, $changed);
				unset($changed[$found_socket]);
			}

			foreach ($changed as $changed_socket) {

				while(socket_recv($changed_socket, $buf, 1024, 0) >= 1)
				{
					$received_text = $this->unmask($buf);	$tst_msg = HTTP::jsonDecode($received_text, true);	$user_name = $tst_msg['name'];	$user_message = $tst_msg['message'];	$user_color = $tst_msg['color'];
					$response_text = $this->mask(HTTP::jsonEncode(['type'=>'usermsg', 'name'=>$user_name, 'message'=>$user_message, 'color'=>$user_color]));
					send_message($response_text);	break 2;	
				}

				$buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
				if ($buf === false) {
					$found_socket = array_search($changed_socket, $clients);
					socket_getpeername($changed_socket, $ip);
					unset($clients[$found_socket]);

					$response = $this->mask(HTTP::jsonEncode(['type'=>'system', 'message'=>$ip.' disconnected']));
					$this->send_message($response);
				}
			}
		}
		socket_close($socket);
	}


	private function send_message($msg)
	{
		global $clients;
		foreach($clients as $changed_socket)
		{
			@socket_write($changed_socket,$msg,strlen($msg));
		}
		return true;
	}
	
	private function unmask($text) {
		// WebSocket frame offsets and lengths are byte-level protocol operations.
		$length = ord($text[1]) & 127;
		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		}
		elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		}
		else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}
	
	private function mask($text)
	{
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);

		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif($length >= 65536)
			$header = pack('CCNN', $b1, 127, $length);
		
		return $header.$text;
	}
	
	private function perform_handshaking($receved_header,$client_conn, $host, $port)
	{
		$headers = array();
		$lines = preg_split("/

		/", $receved_header);
		foreach($lines as $line)
		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}
		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		$upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake

		" .
		"Upgrade: websocket

		" .
		"Connection: Upgrade

		" .
		"WebSocket-Origin: $host

		" .
		"WebSocket-Location: ws://$host:$port/php-ws/chat-daemon.php

		".
		"Sec-WebSocket-Accept:$secAccept



		";
		socket_write($client_conn,$upgrade,strlen($upgrade));
	}
}
