<?php
header("Content-Type: application/json");
require 'vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection("localhost", 5672, "guest", "guest");
$channel = $connection->channel();
$data = json_decode(file_get_contents("php://input"), true);
$method = $data["method"];
$params = $data["params"] ?? [];

function respond($result) {
  echo json_encode(["jsonrpc" => "2.0", "result" => $result, "id" => uniqid()]);
  exit;
}

function ensureUserQueue($user) {
  global $channel;
  $queue = "user.$user";
  $channel->queue_declare($queue, false, true, false, false);
  return $queue;
}

function registerUser($params) {
  $name = $params["name"];
  $lat = $params["lat"];
  $lng = $params["lng"];
  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];
  $users[$name] = [ "lat" => $lat, "lng" => $lng, "online" => false ];
  file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));
  ensureUserQueue($name);
  return "Usuário '$name' registrado.";
}

// function loginUser($params) {
//   $name = $params["name"];
//   $users = json_decode(file_get_contents("users.json"), true);
//   if (!isset($users[$name])) return "Usuário não encontrado.";
//   $users[$name]["online"] = true;
//   file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));
//   return "Login efetuado.";
// }

function loginUser($params) {
  $name = $params["name"];
  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];

  if (!isset($users[$name])) {
    return "Usuário não registrado.";
  }

  $users[$name]["online"] = true;
  $users[$name]["last_seen"] = time();
  file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));
  return "Usuário $name está online.";
}


// function logoutUser($params) {
//   $name = $params["name"];
//   $users = json_decode(file_get_contents("users.json"), true);
//   if (isset($users[$name])) {
//     $users[$name]["online"] = false;
//     file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));
//   }
//   return "Logout realizado.";
// }

function logoutUser($params) {
  $name = $params["name"];
  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];

  if (isset($users[$name])) {
    $users[$name]["online"] = false;
    file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));
    return "Usuário $name está offline.";
  }

  return "Usuário não encontrado.";
}


function sendToUser($params) {
  global $channel;
  $from = $params["from"];
  $to = $params["to"];
  $msg = "[De $from]: " . $params["message"];
  $users = json_decode(file_get_contents("users.json"), true);
  if (isset($users[$to]) && $users[$to]["online"]) {
    file_put_contents("sync-$to.txt", $msg."\n", FILE_APPEND);
    return "Mensagem entregue diretamente.";
  } else {
    $queue = ensureUserQueue($to);
    $channel->basic_publish(new AMQPMessage($msg), "", $queue);
    return "Usuário offline. Mensagem enviada para fila.";
  }
}

function readQueue($params) {
  global $channel;
  $user = $params["user"];
  $consume = $params["consume"] ?? false;
  $queue = ensureUserQueue($user);
  $messages = [];
  while ($msg = $channel->basic_get($queue, $consume)) {
    $messages[] = $msg->body;
    if (!$consume) $channel->basic_nack($msg->delivery_info['delivery_tag'], false, true);
  }
  return $messages;
}

function getSyncMessages($params) {
  $user = $params["user"];
  $file = "sync-$user.txt";
  if (!file_exists($file)) return [];
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  file_put_contents($file, "");
  return $lines;
}

// function listContacts($params) {
//   global $channel;
//   $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];
//   $user = $params["user"] ?? null;
//   $raio = floatval($params["raio"] ?? 999999);
//   if (!$user || !isset($users[$user])) return [];

//   $lat1 = $users[$user]["lat"];
//   $lng1 = $users[$user]["lng"];
//   $contacts = [];

//   foreach ($users as $name => $info) {
//     if ($name === $user) continue;
//     $lat2 = $info["lat"];
//     $lng2 = $info["lng"];
//     if (!is_numeric($lat1) || !is_numeric($lng1) || !is_numeric($lat2) || !is_numeric($lng2)) continue;
//     $dist = calcularDistancia($lat1, $lng1, $lat2, $lng2);
//     if ($dist > $raio) continue;
//     $contacts[] = [
//       "name" => $name,
//       "online" => $info["online"] ?? false,
//       "lat" => $lat2,
//       "lng" => $lng2,
//       "distancia" => round($dist, 2)
//     ];
//   }
//   return $contacts;
// }

function listContacts($params) {
  global $channel;

  $users = file_exists("users.json") ? json_decode(file_get_contents("users.json"), true) : [];

  $user = $params["user"] ?? null;
  $raio = floatval($params["raio"] ?? 999999);
  $now = time();

  if (!$user || !isset($users[$user])) return [];

    $lat1 = $users[$user]["lat"];
    $lng1 = $users[$user]["lng"];

    $contacts = [];

  foreach ($users as $name => $info) {
    if ($name === $user) continue;

    $lat2 = $info["lat"] ?? null;
    $lng2 = $info["lng"] ?? null;

    if (!is_numeric($lat1) || !is_numeric($lng1) || !is_numeric($lat2) || !is_numeric($lng2)) continue;

    $distancia = calcularDistancia($lat1, $lng1, $lat2, $lng2);
    $dentro = $distancia <= $raio;

    $online = ($info["online"] ?? false) && ($now - ($info["last_seen"] ?? 0) < 15);

    $contacts[] = [
      "name" => $name,
      "online" => $online,
      "lat" => $lat2,
      "lng" => $lng2,
      "distancia" => round($distancia, 2),
      "dentro_raio" => $dentro
    ];

  }

  return $contacts;
}


function calcularDistancia($lat1, $lon1, $lat2, $lon2) {
  $R = 6371;
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);
  $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
  $c = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
}

$methods = [
  "registerUser" => "registerUser",
  "loginUser" => "loginUser",
  "logoutUser" => "logoutUser",
  "sendToUser" => "sendToUser",
  "readQueue" => "readQueue",
  "getSyncMessages" => "getSyncMessages",
  "listContacts" => "listContacts"
];

if (isset($methods[$method])) {
  respond(call_user_func($methods[$method], $params));
} else {
  respond(["error" => ["message" => "Método não encontrado"]]);
}

$channel->close();
$connection->close();
