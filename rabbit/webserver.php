#!/usr/bin/php
<?php

set_error_handler(function($errno, $errstr, $errfile, $errline ){
  throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
  die();
});


require_once __DIR__ . "/db/db.php";
require_once __DIR__ . "/rabbitmq-webHost/rabbitMQLib.php";

$db = getDB();
if (!isset($db)) {
  die('DB Error');
}

if (!function_exists('mysql_escape_string')) {
  /**
   * mysql_escape_string — Escapes a string for use in a mysql_query
   *
   * @link https://dev.mysql.com/doc/refman/8.0/en/string-literals.html#character-escape-sequences
   *
   * @param string $unescaped_string
   * @return string
   * @deprecated
   */
  function mysql_escape_string(string $unescaped_string): string
  {
      $replacementMap = [
          "\0" => "\\0",
          "\n" => "\\n",
          "\r" => "\\r",
          "\t" => "\\t",
          chr(26) => "\\Z",
          chr(8) => "\\b",
          '"' => '\"',
          "'" => "\'",
          '_' => "\_",
          "%" => "\%",
          '\\' => '\\\\'
      ];

      return \strtr($unescaped_string, $replacementMap);
  }
}

function doLogin($request) {
  $db = getDB();
  $email = $request['email'];
  $password = $request['password'];

  if(!isset($email) || !isset($password)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare(
    "SELECT id, email, password, first_name, last_name, admin from Users WHERE email = :email LIMIT 1"
  );
  $params = [":email" => $email];
  $r = $stmt->execute($params);
  $e = $stmt->errorInfo();
  if ($e[0] != "00000") {
    return [ 'error' => true, 'msg' => 'Something went wrong, please try again.' ];
  }
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($result && isset($result["password"])) {
    $password_hash_from_db = $result["password"];
    if (password_verify($password, $password_hash_from_db)) {
      unset($result["password"]); //remove password so we don't leak it beyond this page
      return $result;
    } else {
      return [ 'error' => true, 'msg' => 'Invailid password.' ];
    }
  } else {
    return [ 'error' => true, 'msg' => 'Invalid user.' ];
  }
}

function registerUser($request) {
  $db = getDB();

  $email = $request['email'];
  $password = $request['password'];
  $first_name = $request['first_name'];
  $last_name = $request['last_name'];

  if(!isset($email) || !isset($password) || !isset($first_name) || !isset($last_name)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $hash = password_hash($password, PASSWORD_BCRYPT);

  //here we'll use placeholders to let PDO map and sanitize our data
  $stmt = $db->prepare(
    "INSERT INTO Users(email, password, first_name, last_name) VALUES(:email, :password, :first_name, :last_name)"
  );
  //here's the data map for the parameter to data
  $params = [
    ":email" => $email,
    ":password" => $hash,
    ":first_name" => $first_name,
    ":last_name" => $last_name
  ];
  $r = $stmt->execute($params);
  $e = $stmt->errorInfo();
  if ($e[0] == "00000") {
    $stmt = $db->prepare('SELECT id FROM Users WHERE email = :email');
    $r = $stmt->execute([':email' => $email]);
    if($r) {
      $id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
      $stmt = $db->prepare('INSERT INTO Balance(user_id) VALUES(:user_id)');
      $stmt->execute([':user_id' => $id]);
    } else {
      return [ 'error' => true, 'msg' => 'An error occurred, please try again.' ];
    }
    return [ 'error' => false ];
  } else {
    if ($e[0] == "23000") {
      return [ 'error' => true, 'msg' => 'Email already exists!' ];
    } else {
      return [ 'error' => true, 'msg' => 'An error occurred, please try again.' ];
    }
  }
}

function checkEmail($email) {
  $db = getDB();

  $stmt = $db->prepare(
    "SELECT COUNT(1) as InUse from Users where email = :email"
  );
  $stmt->execute([":email" => $email]);
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  $inUse = 1;
  if ($result && isset($result["InUse"])) {
    try {
      $inUse = intval($result["InUse"]);
    } catch (Exception $e) {
      $inUse = 1;
    }
  }

  return $inUse;
}

function updateProfile($request) {
  $db = getDB();

  $id = $request['id'];
  $email = $request['email'];
  $newEmail = $request['new_email'];
  $firstName = $request['first_name'];
  $lastName = $request['last_name'];
  $password = $request['password'];
  $confirm = $request['confirm'];

  if(!isset($id) || !isset($email) || !isset($newEmail) || !isset($password) || !isset($firstName) || !isset($lastLame) || !isset($confirm)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  if ($email != $newEmail) {
    $inUse = checkEmail($newEmail);
    if ($inUse > 0) {
      return [ 'error' => true, 'msg' => 'Email already in use!' ];
    } else {
      $email = $newEmail;
    }
  }

  $msgs = [];

  $stmt = $db->prepare(
    "UPDATE Users set email = :email, first_name = :first_name, last_name = :last_name where id = :id"
  );
  $r = $stmt->execute([
    ":email" => $email,
    ":id" => $id,
    ":first_name" => $firstName,
    ":last_name" => $lastName
  ]);
  if ($r) {
    $msgs[] = "Updated profile.";
    if (!empty($password) && !empty($confirm)) {
      if ($password == $confirm) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        //this one we'll do separate
        $stmt = $db->prepare(
          "UPDATE Users set password = :password where id = :id"
        );
        $r = $stmt->execute([":id" => $id, ":password" => $hash]);
        if ($r) {
          $msgs[] = "Changed password.";
        } else {
          return [ 'error' => true, 'msg' => 'Error changing password.' ];
        }
      }
    }

    $stmt = $db->prepare(
      "SELECT email, first_name, last_name from Users WHERE id = :id LIMIT 1"
    );
    $stmt->execute([":id" => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
      return [ 'result' => $result, 'msgs' => $msgs ];
    } else {
      return [ 'error' => true, 'msg' => 'Error updating profile.' ];
    }
  }
  return [ 'error' => true, 'msg' => 'Error updating profile.' ];
}

function getBalance($request) {
  $db = getDB();
  $user = $request['user'];
  $page = $request['page'];

  if(!isset($user) || !isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare(
    "SELECT amount, last_updated
    FROM Balance
    WHERE user_id = :q"
  );
  $r = $stmt->execute([":q" => $user]);
  if ($r) {
    $balance = $stmt->fetch(PDO::FETCH_ASSOC);
  } else {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  $per_page = 10;

  $stmt = $db->prepare(
    "SELECT count(*) as total
    FROM Transactions
    WHERE user_id = :q
    ORDER BY created DESC"
  );
  $r = $stmt->execute([':q' => $user]);
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  if($result){
    $total = (int)$result["total"];
  } else {
    $total = 0;
  }
  
  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $stmt = $db->prepare(
    "SELECT created, amount, expected_balance
    FROM Transactions
    WHERE user_id = :q
    ORDER BY created DESC LIMIT :offset,:count"
  );
  $stmt->bindValue(":q", $user);
  $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
  $stmt->bindValue(":count", $per_page, PDO::PARAM_INT);
  $r = $stmt->execute();
  if ($r) {
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  return [ 'balance' => $balance, 'transactions' => $transactions, 'total_pages' => $total_pages ];
}

function changeBalance($request) {
  $db = getDB();
  $user = $request['user'];
  $balChange = $request['balance'];

  if(!isset($user) || !isset($balChange)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare(
    "SELECT sum(amount) AS sum
    FROM Transactions
    WHERE created > now() - interval 24 hour
    AND user_id = :id"
  );
  $r = $stmt->execute([':id' => $user]);
  if ($r) {
    $sum = $stmt->fetch(PDO::FETCH_ASSOC)['sum'];
    if( $sum + $balChange > 500 ) {
      return [ 'error' => true, 'msg' => 'Maximum $500 deposit per day!' ];
    } else {
      // Current Balance
      $stmt = $db->prepare("SELECT amount from Balance WHERE user_id = :id");
      $r = $stmt->execute([":id" => $user]);
      if (!$r) {
        return [ 'error' => true, 'msg' => 'Error doing transaction!' ];
      }
      $currentBal = $stmt->fetch(PDO::FETCH_ASSOC)['amount'];

      // Insert Transaction
      $transactions = $db->prepare(
        "INSERT INTO Transactions (user_id, amount, expected_balance)
        VALUES (:id, :amount, :expected_balance)"
      );
      $balance = $db->prepare(
        "UPDATE Balance SET amount = :balance WHERE user_id = :id"
      );

      // Calc
      $finalBalance = $currentBal + $balChange;

      $r = $transactions->execute([
        ":id" => $user,
        ":amount" => $balChange,
        ":expected_balance" => $finalBalance
      ]);
      if (!$r) {
        return [ 'error' => true, 'msg' => 'Error doing transaction!' ];
      }

      $r = $balance->execute([":balance" => $finalBalance, ":id" => $user]);
      if (!$r) {
        return [ 'error' => true, 'msg' => 'Error doing transaction!' ];
      }

      return [ 'error' => false ];
    }
  }
  return [ 'error' => true, 'msg' => 'Error doing transaction!' ];
}

function getPortfolio($request) {
  $db = getDB();
  $user = $request['user'];
  $page = $request['page'];

  if(!isset($user) || !isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare(
    "SELECT count(*) as total, max(Trade.created) as last
    FROM Portfolio
    JOIN Trade ON Trade.id = Portfolio.last_trade_id
    WHERE Portfolio.user_id = :user_id AND held_shares != 0"
  );
  $r = $stmt->execute([':user_id' => $user]);
  if ($r) {
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)$data["total"];
  } else {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  $per_page = 10;

  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $stmt = $db->prepare(
    "SELECT *, Stock_Data.created AS updated, (Stock_Data.value * Portfolio.held_shares) AS totalValue
    FROM Portfolio
    JOIN Stocks ON Stocks.symbol = Portfolio.symbol
    JOIN Stock_Data ON Stock_Data.symbol = Portfolio.symbol
    WHERE user_id = :user_id
    AND held_shares != 0
    AND (Portfolio.symbol, Stock_Data.created) IN (SELECT symbol, max(created) FROM Stock_Data GROUP BY symbol)
    ORDER BY totalValue DESC LIMIT :offset,:count"
  );
  $stmt->bindValue(":user_id", $user);
  $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
  $stmt->bindValue(":count", $per_page, PDO::PARAM_INT);
  $r = $stmt->execute();
  if ($r) {
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  return [ 'results' => $results, 'data' => $data, 'total_pages' => $total_pages ];
}

function getStockDetail($request) {
  $db = getDB();
  $symbol = $request['symbol'];
  $sqlTime = mysql_escape_string($request['sql_time']);

  if(!isset($symbol) || !isset($sqlTime)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare(
    "SELECT *
    FROM Stock_Data
    JOIN Stocks ON Stocks.symbol = Stock_Data.symbol
    WHERE Stock_Data.symbol = :symbol
    AND created > now() - interval $sqlTime
    ORDER BY created ASC"
  );
  $r = $stmt->execute([':symbol' => $symbol]);
  if ($r) {
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
}

function getStocks($request) {
  $db = getDB();
  $page = $request['page'];

  if(!isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $per_page = 10;

  $stmt = $db->query("SELECT count(*) as total FROM Stocks");
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  if($result){
    $total = (int)$result["total"];
  } else {
    $total = 0;
  }
  
  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $r = $db->query(
    "SELECT *
    FROM Stock_Data
    JOIN Stocks ON Stocks.symbol = Stock_Data.symbol
    WHERE (Stock_Data.symbol, created) IN (SELECT symbol, max(created) FROM Stock_Data GROUP BY symbol)
    ORDER BY Stock_Data.symbol ASC"
  );
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }
  return [ 'results' => $r->fetchAll(PDO::FETCH_ASSOC), 'total_pages' => $total_pages ];
}

function getAllStocks($request) {
  $db = getDB();

  $r = $db->query(
    "SELECT *
    FROM Stock_Data
    JOIN Stocks ON Stocks.symbol = Stock_Data.symbol
    WHERE (Stock_Data.symbol, created) IN (SELECT symbol, max(created) FROM Stock_Data GROUP BY symbol)
    ORDER BY Stock_Data.symbol ASC"
  );
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }
  return $r->fetchAll(PDO::FETCH_ASSOC);
}

function getArbitrageOpportunities($request) {
  require_once __DIR__ . "/arbitrage.php";
  
  $db = getDB();
  
  $start = $request['start']; 

  $r = $db->query("SELECT symbol FROM Currencies ORDER BY symbol ASC");
  $currencies =  $r->fetchAll(PDO::FETCH_COLUMN);
  $rates = [];
  
  $stmt = $db->prepare(
    "SELECT rate
    FROM ExchangeRates
    WHERE (created >= CURRENT_DATE - INTERVAL 1 DAY AND source = :source)
    ORDER BY dest"
  );
  
  foreach ($currencies as $currency) {
    $r = $stmt->execute($data);
    if (!$r) {
      continue;
    }
    
    $fetchRates = $r->fetchAll(PDO::FETCH_ASSOC);
    $rawRates = [];
    
    foreach($fetchRates as $rate) {
      array_push($rawRates, $rate['rate']);
    }
    array_push($rates, $rawRates);
  }
  
  return arbitrage($currencies, $rates, $start);
}

function getTradeHistory($request) {
  $db = getDB();
  $user = $request['user'];
  $page = $request['page'];

  if(!isset($user) || !isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $per_page = 10;

  $stmt = $db->prepare(
    "SELECT count(*) as total
    FROM Trade
    WHERE user_id = :user_id"
  );
  $r = $stmt->execute([':user_id' => $user]);
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  if($result){
    $total = (int)$result["total"];
  } else {
    $total = 0;
  }
  
  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $stmt = $db->prepare(
    "SELECT *,(shares * value) AS amount
    FROM Trade
    JOIN Stock_Data ON Stock_Data.id = Trade.stock_data_id
    JOIN Stocks ON Stocks.symbol = Trade.symbol
    WHERE user_id = :user_id
    ORDER BY Trade.created DESC LIMIT :offset,:count"
  );
  $stmt->bindValue(":user_id", $user);
  $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
  $stmt->bindValue(":count", $per_page, PDO::PARAM_INT);
  $r = $stmt->execute();
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }
  return [ 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_pages' => $total_pages ];
}

function watchSymbol($request) {
  $db = getDB();

  $user = $request['user'];
  $symbol = $request['symbol'];

  if(!isset($user) || !isset($symbol)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare('INSERT INTO Watching(user_id, symbol) VALUES(:user_id, :symbol)');
  $r = $stmt->execute([':user_id' => $user, ':symbol' => $symbol]);
  if(!$r) {
    return [ 'error' => true, 'msg' => 'An error occurred, please try again.' ];
  }
  return [ 'error' => false ];
}

function unwatchSymbol($request) {
  $db = getDB();

  $id = $request['id'];

  if(!isset($id)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare('DELETE FROM Watching WHERE id = :id');
  $r = $stmt->execute([':id' => $id]);
  if(!$r) {
    return [ 'error' => true, 'msg' => 'An error occurred, please try again.' ];
  }
  return [ 'error' => false ];
}

function getWatchList($request) {
  $db = getDB();
  $user = $request['user'];
  $page = $request['page'];

  if(!isset($user) || !isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $per_page = 10;

  $stmt = $db->query("SELECT count(*) as total FROM Watching");
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  if($result){
    $total = (int)$result["total"];
  } else {
    $total = 0;
  }
  
  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $stmt = $db->prepare(
    "SELECT *, Watching.id AS watch_id
    FROM Watching
    JOIN Stocks ON Stocks.symbol = Watching.symbol
    JOIN Stock_Data ON Stock_Data.symbol = Watching.symbol
    WHERE (Watching.symbol, created) IN (SELECT symbol, max(created) FROM Stock_Data GROUP BY symbol)
    AND user_id = :user_id
    ORDER BY Watching.symbol ASC"
  );
  $r = $stmt->execute([':user_id' => $user]);
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  return [ 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_pages' => $total_pages ];
}

function getTradesAdmin($request) {
  $db = getDB();
  $page = $request['page'];

  if(!isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $r = $db->query("SELECT count(*) as total, MAX(created) as date FROM Trade");
  if ($r) {
    $tradesData = $r->fetch(PDO::FETCH_ASSOC);
    $total = (int)$tradesData["total"];
  } else {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  $per_page = 10;

  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $stmt = $db->prepare(
    "SELECT id, created, symbol, shares, commission_id
    FROM Trade
    ORDER BY created DESC LIMIT :offset,:count"
  );
  $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
  $stmt->bindValue(":count", $per_page, PDO::PARAM_INT);
  $r = $stmt->execute();
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  return [ 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'data' => $tradesData, 'total_pages' => $total_pages ];
}

function getBalancesAdmin($request) {
  $db = getDB();
  $page = $request['page'];

  if(!isset($page)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $r = $db->query("SELECT sum(amount) as total, MAX(last_updated) as date FROM Balance");
  if ($r) {
    $balanceData = $r->fetch(PDO::FETCH_ASSOC);
  } else {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  $result = $db->query("SELECT count(*) as total FROM Transactions");
  if($result){
    $total = (int)$result->fetch(PDO::FETCH_ASSOC)["total"];
  } else {
    $total = 0;
  }
  
  $per_page = 10;
  $total_pages = ceil($total / $per_page);
  $offset = ($page - 1) * $per_page;
  
  $stmt = $db->prepare(
    "SELECT created, amount, expected_balance
    FROM Transactions
    ORDER BY created DESC LIMIT :offset,:count"
  );
  $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
  $stmt->bindValue(":count", $per_page, PDO::PARAM_INT);
  $r = $stmt->execute();
  if (!$r) {
    return [ 'error' => true, 'msg' => 'There was a problem fetching the results.' ];
  }

  return [ 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'data' => $balanceData, 'total_pages' => $total_pages ];
}

function tradeShare($request) {
  $db = getDB();

  $user = $request['user'];
  $type = $request['trade_type'];
  $symbol = $request['symbol'];
  $shareChange = $request['shares'];

  if(!isset($user) || !isset($type) || !isset($symbol) || !isset($shareChange)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  if ( $type != "buy" && $type != "sell" ) {
    echo $type;
    return [ 'error' => true, 'msg' => 'Invalid type.' ];
  }

  $shareChange = abs($shareChange);

  // Get latest stock value
  $stmt = $db->prepare("SELECT id, value FROM Stock_Data WHERE created = (SELECT max(created) FROM Stock_Data WHERE symbol = :symbol) AND symbol = :symbol");
  $r = $stmt->execute([":symbol" => $symbol]);
  if( $r ) {
    $currentData = $stmt->fetch(PDO::FETCH_ASSOC);
  } else {
    return [ 'error' => true, 'msg' => 'SQL ERROR' ];
  }

  // Currently Held Shares
  $stmt = $db->prepare("SELECT held_shares from Portfolio WHERE user_id = :id AND symbol = :symbol");
  $r = $stmt->execute([":id" => $user, ":symbol" => $symbol]);
  if( $r ) {
    $currentHeldShares = $stmt->fetch(PDO::FETCH_ASSOC);
  } else {
    return [ 'error' => true, 'msg' => 'SQL ERROR' ];
  }

  // Check user balance
  $stmt = $db->prepare("SELECT amount from Balance WHERE user_id = :id");
  $r = $stmt->execute([":id" => $user]);
  if( $r ) {
    $currentBal = $stmt->fetch(PDO::FETCH_ASSOC)['amount'];
  } else {
    return [ 'error' => true, 'msg' => 'SQL ERROR' ];
  }

  $stockValue = $shareChange * $currentData['value'];

  // Check if user can buy shares
  if ( $stockValue > $currentBal && $type == 'buy' ) {
    return [ 'error' => true, 'msg' => 'Not enough funds to buy shares.' ];
  }

  // Prepared Statements
  $trade = $db->prepare(
    "INSERT INTO Trade (user_id, symbol, shares, expected_shares, stock_data_id)
    VALUES (:id, :symbol, :shares, :expected_shares, :stock_data_id)"
  );
  $transactions = $db->prepare(
    "INSERT INTO Transactions (user_id, amount, expected_balance)
    VALUES (:id, :amount, :expected_balance)"
  );
  $balance = $db->prepare(
    "UPDATE Balance SET amount = :balance WHERE user_id = :id"
  );
  if ( $type == 'buy' && !$currentHeldShares ) {
    $portfolio = $db->prepare(
      "INSERT INTO Portfolio(user_id, symbol, last_trade_id, initial_trade_id, initial_shares, held_shares)
      VALUES (:id, :symbol, :trade_id, :trade_id, :shares, :shares)"
    );
  } else {
    $portfolio = $db->prepare(
      "UPDATE Portfolio SET last_trade_id = :trade_id, held_shares = :shares WHERE user_id = :id AND symbol = :symbol"
    );
  }

  if ( $type == 'buy' ) {
    $r = $trade->execute([
      ":id" => $user,
      ":symbol" => $symbol,
      ":shares" => $shareChange,
      ":expected_shares" => !$currentHeldShares ? $shareChange : $currentHeldShares['held_shares'] + $shareChange,
      ":stock_data_id" => $currentData['id'],
    ]);
    if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
    $tradeId = $db->lastInsertId();
    $r = $portfolio->execute([
      ":id" => $user,
      ":symbol" => $symbol,
      ":trade_id" => $tradeId,
      ":shares" => !$currentHeldShares ? $shareChange : $currentHeldShares['held_shares'] + $shareChange
    ]);
    if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
    $r = $transactions->execute([
      ":id" => $user,
      ":amount" => -$stockValue,
      ":expected_balance" => $currentBal - $stockValue
    ]);
    if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
    $r = $balance->execute([
      ":id" => $user,
      ":balance" => $currentBal - $stockValue
    ]);
    if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
  }

  if ( $type == 'sell' ) {
    if ( !$currentHeldShares ) {
      return [ 'error' => true, 'msg' => 'Cannot sell shares of a security not owned.' ];
    } else {
    // Check if user can sell shares
      $currentHeldShares = $currentHeldShares['held_shares'];
      if ( $currentHeldShares == 0 && $type == 'sell') {
        return [ 'error' => true, 'msg' => 'Cannot sell shares of a security not owned.' ];
      }
      if ( $shareChange > $currentHeldShares && $type == 'sell' ) {
        return [ 'error' => true, 'msg' => 'Cannot sell more shares can currently owned.' ];
      }
      $r = $trade->execute([
        ":id" => $user,
        ":symbol" => $symbol,
        ":shares" => -$shareChange,
        ":expected_shares" => $currentHeldShares - $shareChange,
        ":stock_data_id" => $currentData['id'],
      ]);
      if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
      $tradeId = $db->lastInsertId();
      $r = $portfolio->execute([
        ":id" => $user,
        ":symbol" => $symbol,
        ":trade_id" => $tradeId,
        ":shares" => $currentHeldShares - $shareChange
      ]);
      if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
      $r = $transactions->execute([
        ":id" => $user,
        ":amount" => $stockValue,
        ":expected_balance" => $currentBal + $stockValue
      ]);
      if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
      $r = $balance->execute([
        ":id" => $user,
        ":balance" => $currentBal + $stockValue
      ]);
      if( !$r ) { return [ 'error' => true, 'msg' => 'SQL ERROR' ]; }
    }
  }

  return [ 'error' => false ];
}

function watchPush($request) {
  $db = getDB();

  $user = $request['user_id'];
  $id = $request['id'];
  $greaterOrLower = $request['greaterOrLower'];
  $amount = $request['amount'];

  if(!isset($user) || !isset($id) ||!isset($greaterOrLower) ||!isset($amount)) {
    return [ 'error' => true, 'msg' => 'Error: RMQ Missing fields!' ];
  }

  $stmt = $db->prepare(
    "UPDATE Watching SET push = 1, greater_or_lower = :got, watchValue = :value WHERE user_id = :user_id AND id = :id"
  );
  $r = $stmt->execute([
    ":user_id" => $user,
    ":id" => $id,
    ":got" => $greaterOrLower,
    ":value" => $amount
  ]);
  if( !$r ) {
    return [ 'error' => true, 'msg' => 'SQL ERROR' ];
  }
  return [ 'error' => false ];
}

function requestHandler($request) {
  if ($request['type'] == 'login') {
    return doLogin($request);
  }
  if ($request['type'] == 'registerUser') {
    return registerUser($request);
  }
  if ($request['type'] == 'updateProfile') {
    return updateProfile($request);
  }
  if ($request['type'] == 'getBalance') {
    return getBalance($request);
  }
  if ($request['type'] == 'changeBalance') {
    return changeBalance($request);
  }
  if ($request['type'] == 'getPortfolio') {
    return getPortfolio($request);
  }
  if ($request['type'] == 'getStockDetail') {
    return getStockDetail($request);
  }
  if ($request['type'] == 'getStocks') {
    return getStocks($request);
  }
  if ($request['type'] == 'getAllStocks') {
    return getAllStocks($request);
  }
  if ($request['type'] == 'getArbitrageOpportunities') {
    return getArbitrageOpportunities($request);
  }
  if ($request['type'] == 'getTradeHistory') {
    return getTradeHistory($request);
  }
  if ($request['type'] == 'watchSymbol') {
    return watchSymbol($request);
  }
  if ($request['type'] == 'unwatchSymbol') {
    return unwatchSymbol($request);
  }
  if ($request['type'] == 'getWatchList') {
    return getWatchList($request);
  }
  if ($request['type'] == 'getTradesAdmin') {
    return getTradesAdmin($request);
  }
  if ($request['type'] == 'getBalancesAdmin') {
    return getBalancesAdmin($request);
  }
  if ($request['type'] == 'tradeShare') {
    return tradeShare($request);
  }
  if ($request['type'] == 'watchPush') {
    return watchPush($request);
  }
  return [ 'error' => true, 'msg' => 'Error: RMQ No Type' ];
}

$server = new rabbitMQConsumer('amq.direct', 'webserver');
$server->process_requests('requestHandler');

?>
