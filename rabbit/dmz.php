#!/usr/bin/php
<?php

set_error_handler(function($errno, $errstr, $errfile, $errline ){
  throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
  die();
});


require_once __DIR__ . "/db/db.php";
require_once __DIR__ . "/rabbitmq-dmzHost/rabbitMQLib.php";

$db = getDB();
if (!isset($db)) {
  die('DB Error');
}

function getAllStocks($request) {
  $db = getDB();

  $r = $db->query("SELECT symbol FROM Stocks ORDER BY symbol ASC");
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }
  return $r->fetchAll(PDO::FETCH_COLUMN);
}

function insertStocks($request) {
  $db = getDB();

  if(!isset($request['data'])) {
    return [ 'error' => true, 'msg' => 'No data given.' ];
  }

  $stmt = $db->prepare(
    "INSERT INTO Stock_Data(symbol, created, value) VALUES(:symbol, from_unixtime(:created), :value)"
  );
  foreach($request['data'] as $data) {
    $r = $stmt->execute($data);
    if (!$r) {
      continue;
    }
  }
  return [ 'error' => false ];
}


function getWatchedStocks($request) {
  $db = getDB();

  $r = $db->query(
    "SELECT Watching.symbol, greater_or_lower, watchValue, email, value, created
    FROM Watching
    JOIN Users ON Users.id = Watching.user_id
    JOIN Stock_Data ON Stock_Data.symbol = Watching.symbol AND Stock_Data.created = (SELECT max(created) FROM Stock_Data WHERE symbol = Watching.symbol)
    WHERE push = 1"
  );
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  return $r->fetchAll(PDO::FETCH_ASSOC);
}

function requestHandler($request) {
  if ($request['type'] == 'getAllStocks') {
    return getAllStocks($request);
  }
  if ($request['type'] == 'insertStocks') {
    return insertStocks($request);
  }
  if ($request['type'] == 'getWatchedStocks') {
    return getWatchedStocks($request);
  }
  return [ 'error' => true, 'msg' => 'Error: RMQ No Type' ];
}

$server = new rabbitMQConsumer('amq.direct', 'dmz');
$server->process_requests('requestHandler');

?>
