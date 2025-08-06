<?php

	require 'vendor/autoload.php';
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;

	header('Content-Type: application/json');

	$request = json_decode(file_get_contents("php://input"), true);
	$method = $request["method"] ?? null;
	$params = $request["params"] ?? [];

	$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
	$channel = $connection->channel();

	// Declara fila única para localizações
	$channel->queue_declare('geo.locations', false, true, false, false);

	function respond($result) {
		echo json_encode(["jsonrpc" => "2.0", "result" => $result, "id" => uniqid()]);
		exit;
	}

	function sendLocation($params) {
		global $channel;

		$msg = new AMQPMessage(json_encode($params), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
		$channel->basic_publish($msg, '', 'geo.locations');

		return "Localização enviada";
	}

	function getLocations() {
		$file = "locations.json";
		if (!file_exists($file)) return [];

		$json = file_get_contents($file);
		return json_decode($json, true);
	}

	function consumeOnce() {
		global $channel;
		$messages = [];

		$channel->basic_consume('geo.locations', '', false, true, false, false, function($msg) use (&$messages) {
			$data = json_decode($msg->body, true);
			if ($data && isset($data['device'])) {
				$messages[] = $data;
			}
		});

		$channel->wait(null, true, 1);

		if ($messages) {
			$file = "locations.json";
			$existing = [];

			if (file_exists($file)) {
				$existing = json_decode(file_get_contents($file), true);
				if (!is_array($existing)) $existing = [];
			}

			foreach ($messages as $msg) {
				$existing[$msg['device']] = $msg;
			}

			file_put_contents($file, json_encode(array_values($existing), JSON_PRETTY_PRINT));
		}
	}

	function readMessages($user, $consume = false) {
	    $conn = connect();
	    $ch = $conn->channel();
	    $ch->queue_declare($user, false, true, false, false);

	    $messages = [];

	    while (true) {
	        $msg = $ch->basic_get($user, $consume);
	        if (!$msg) break;
	        $messages[] = $msg->body;
	    }

	    $ch->close();
	    $conn->close();
	    return $messages;
	}


	$availableMethods = ['sendLocation', 'getLocations'];

	if (in_array($method, $availableMethods)) {
		if ($method === 'getLocations') consumeOnce();
		respond(call_user_func($method, $params));
	} else {
		echo json_encode(["error" => ["code" => -32601, "message" => "Método não encontrado"]]);
	}

	$channel->close();
	$connection->close();
?>
