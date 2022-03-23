#!/usr/bin/php
<?php

set_error_handler(function($errno, $errstr, $errfile, $errline ){
  throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
  die();
});


require_once __DIR__ . "/db/db.php";
require_once __DIR__ . "/rabbitmq-pushHost/rabbitMQLib.php";

$db = getDB();
if (!isset($db)) {
  die('DB Error');
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

$server = new rabbitMQConsumer('amq.direct', 'push');
$server->process_requests('getWatchedStocks');

?>
