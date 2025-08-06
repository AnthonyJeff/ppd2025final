<?php
header("Content-Type: application/json");
require 'vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$data = json_decode(file_get_contents("php://input"), true);
$method = $data["method"];
$params = $data["params"] ?? [];

function respond($result) {
  echo json_encode(["jsonrpc" => "2.0", "result" => $result, "id" => uniqid()]);
  exit;
}

// Conexão com RabbitMQ
$connection = new AMQPStreamConnection("localhost", 5672, "guest", "guest");
$channel = $connection->channel();

// Cria fila se não existir
function ensureUserQueue($user) {
  global $channel;
  $queueName = "user.$user";
  $channel->queue_declare($queueName, false, true, false, false);
  return $queueName;
}

// 1. Registro de usuário com geolocalização (local - JSON)
function registerUser($params) {
  $name = $params["name"];
  $lat = $params["lat"];
  $lng = $params["lng"];

  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];

  $users[$name] = [ "lat" => $lat, "lng" => $lng ];
  file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));

  ensureUserQueue($name);
  return "Usuário '$name' registrado com localização.";
}

// 2. Enviar mensagem para outro usuário via RabbitMQ
function sendToUser($params) {
  global $channel;
  $from = $params["from"];
  $to = $params["to"];
  $msg = $params["message"];

  $queue = ensureUserQueue($to);
  $fullMessage = "[De $from]: $msg";
  $message = new AMQPMessage($fullMessage);

  $channel->basic_publish($message, "", $queue);
  return "Mensagem enviada para $to.";
}

// 3. Ler mensagens da própria fila (sem consumir)
// function readQueue($params) {
//   global $channel;
//   $user = $params["user"];
//   $consume = $params["consume"] ?? false;

//   $queue = ensureUserQueue($user);
//   $messages = [];

//   while ($msg = $channel->basic_get($queue, $consume)) {
//     $messages[] = $msg->body;
//     if (!$consume) {
//       $channel->basic_nack($msg->delivery_info['delivery_tag'], false, true);
//       break;
//     }
//   }

//   return $messages;
// }

// function readQueue($params) {
//   global $channel;
//   $user = $params["user"];
//   $consume = $params["consume"] ?? false;

//   $queue = ensureUserQueue($user);
//   $messages = [];

//   while (true) {
//     $msg = $channel->basic_get($queue, $consume);
//     if (!$msg) break;

//     $messages[] = $msg->body;

//     if (!$consume) {
//       // Rejeita sem remover da fila
//       $channel->basic_nack($msg->delivery_info['delivery_tag'], false, true);
//     }
//   }

//   return $messages;
// }
function readQueue($params) {
  global $channel;
  $user = $params["user"];
  $consume = $params["consume"] ?? false;

  $queue = ensureUserQueue($user);
  $messages = [];

  while (true) {
    $msg = $channel->basic_get($queue, $consume);
    if (!$msg) break;

    $messages[] = $msg->body;

    if (!$consume) {
      // Rejeita a mensagem para não removê-la da fila
      $channel->basic_nack($msg->delivery_info['delivery_tag'], false, true);
    }
  }

  return $messages;
}


// 4. Listar usuários registrados
function listUsers() {
  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];
  return array_keys($users);
}

function sendLocation($params) {
    global $channel;

    $channel->queue_declare("geo.locations", false, true, false, false);

    $msg = new AMQPMessage(json_encode($params), ['delivery_mode' => 2]);
    $channel->basic_publish($msg, '', "geo.locations");

    return "Localização enviada";
}

// function listContacts($params) {
//   global $channel;

//   $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];

//   $contacts = [];

//   foreach ($users as $name => $info) {
//     $queue = "user.$name";

//     try {
//       // Consulta passiva: apenas verifica se a fila existe
//       $channel->queue_declare($queue, true, true, false, false, true);
//       $online = true;
//     } catch (Exception $e) {
//       // Fila não existe → offline
//       $online = false;
//     }

//     $contacts[] = [
//       "name" => $name,
//       "online" => $online
//     ];
//   }

//   return $contacts;
// }

function listContacts($params) {
  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];
  $contacts = [];

  // Consulta todas as filas existentes via API do RabbitMQ
  $queues = json_decode(file_get_contents("http://guest:guest@localhost:15672/api/queues/%2F"), true);
  $fila_nomes = array_column($queues, 'name');

  foreach ($users as $name => $info) {
    $queue = "user.$name";
    $online = in_array($queue, $fila_nomes);

    $contacts[] = [
      "name" => $name,
      "online" => $online,
      "lat" => $info["lat"] ?? null,
      "lng" => $info["lng"] ?? null
    ];
  }

  return $contacts;
}





// Dispatcher de métodos
$methods = [
  "registerUser" => "registerUser",
  "sendToUser" => "sendToUser",
  "readQueue" => "readQueue",
  "listUsers" => "listUsers",
  "sendLocation" => "sendLocation",
  "getLocations" => "getLocations",
  "listContacts" => "listContacts",
];

if (isset($methods[$method])) {
  respond(call_user_func($methods[$method], $params));
} else {
  echo json_encode(["error" => ["code" => -32601, "message" => "Método não encontrado"]]);
}

$channel->close();
$connection->close();
