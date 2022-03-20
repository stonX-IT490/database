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

function requestHandler($request) {
  if ($request['type'] == 'getAllStocks') {
    return getAllStocks($request);
  }
  return [ 'error' => true, 'msg' => 'Error: RMQ No Type' ];
}

$server = new rabbitMQConsumer('amq.direct', 'dmz');
$server->process_requests('requestHandler');

?>
