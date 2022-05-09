#!/usr/bin/php
<?php

//Get a copy of $graph with each of its elements being -log(respective element)
function neg_log_matrix($graph) {
  $newarr = [];
  foreach ($graph as $row) {
    $newrow = [];
    foreach($row as $item) {
      array_push($newrow, log($item) * -1);
    }
    array_push($newarr, $newrow);
  }
  return $newarr;
}

//Get the path of arbitrage
function arbitragePath($rates, $currencies, $pre, $dest_curr) {
  $current = $dest_curr;
  $after = -1;
  $path = [];

  while ($pre[$current] != -1) {
    array_unshift($path, [
      "Before Currency" => $currencies[$pre[$current]],
      "After Currency" => $currencies[$current],
      "Rate" => $rates[$pre[$current]][$current]
    ]);

    if ($after == $pre[$current]) {
      break;
    }

    $after = $current;
    $current = $pre[$current];
  }

  array_push($path, [
    "Before Currency" => $currencies[$dest_curr],
    "After Currency" => $currencies[$pre[$current]],
    "Rate" => $rates[$dest_curr][$pre[$current]]
  ]);

  return $path;
}

//Do arbitrage
function arbitrage($currencies, $rates, $start) {
  $trans_graph = neg_log_matrix($rates);

  //source vertex
  $source = array_search($start, $currencies);
  $n = count($trans_graph);

  //$min_dist[i] = minimum known distance from start to node i
  $min_dist = [];
  //$pre[i] = previous node to node i in the path
  $pre = [];

  for ($i = 0; $i < $n; $i++) {
    array_push($min_dist, INF);
    array_push($pre, -1);
  }

  $min_dist[$source] = $source;

  //Bellman Ford Algorithm
  for ($i = 0; $i < $n - 1; $i++) {
    for ($source_curr = 0; $source_curr < $n; $source_curr++) {
      for ($dest_curr = 0; $dest_curr < $n; $dest_curr++) {
        if ($min_dist[$dest_curr] > $min_dist[$source_curr] + $trans_graph[$source_curr][$dest_curr]) {
          $min_dist[$dest_curr] = $min_dist[$source_curr] + $trans_graph[$source_curr][$dest_curr];
          $pre[$dest_curr] = $source_curr;
        }
      }
    }
  }

  $paths = [];

  //Check for negative weight cycles
  for ($i = 0; $i < $n - 1; $i++) {
    for ($source_curr = 0; $source_curr < $n; $source_curr++) {
      for ($dest_curr = 0; $dest_curr < $n; $dest_curr++) {
        if ($min_dist[$dest_curr] > $min_dist[$source_curr] + $trans_graph[$source_curr][$dest_curr]) {
          array_push($paths, arbitragePath($rates, $currencies, $pre, $dest_curr));
        }
      }
    }
  }

  return $paths;
}

function filterArbitragePaths($arbitragePaths) {
  $finalRates = [];
  $filteredArbs = [];
  $finalArbs = [];

  foreach ($arbitragePaths as $arb) {
    $finalRate = 1;
    foreach ($arb as $conversion) {
      $finalRate *= $conversion["Rate"];
    }

    if (!in_array(round($finalRate, 2), $finalRates) && ($finalRate > 1)) {
      array_push($finalRates, round($finalRate, 2));
      array_push($filteredArbs, $arb);
    }
  }

  $maxcount = count(max($filteredArbs));

  foreach ($filteredArbs as $arb) {
    if (count($arb) == $maxcount) {
      array_push($finalArbs, $arb);
    }
  }

  return $finalArbs;
}

?>
