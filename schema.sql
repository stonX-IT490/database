CREATE DATABASE stonx DEFAULT CHARACTER SET = 'utf8mb4';

USE stonx;

CREATE TABLE IF NOT EXISTS `Users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` tinytext NOT NULL,
  `password` tinytext NOT NULL,
  `opened_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `first_name` tinytext NOT NULL,
  `last_name` tinytext NOT NULL,
  `admin` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`) USING HASH
);

CREATE TABLE IF NOT EXISTS `Balance` (
  `user_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) unsigned DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `Balance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`)
);

CREATE TABLE IF NOT EXISTS `Transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount` decimal(10,2) NOT NULL,
  `expected_balance` decimal(10,2) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `Transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`)
);

CREATE TABLE IF NOT EXISTS `Stocks` (
  `symbol` varchar(6) NOT NULL,
  `company_name` text NOT NULL,
  PRIMARY KEY (`symbol`)
);


CREATE TABLE IF NOT EXISTS `Stock_Data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `symbol` varchar(6) NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `value` decimal(10,2) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_index` (`symbol`,`created`),
  KEY `symbol` (`symbol`),
  CONSTRAINT `Stock_Data_ibfk_1` FOREIGN KEY (`symbol`) REFERENCES `Stocks` (`symbol`)
);

CREATE TABLE IF NOT EXISTS `Watching` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `symbol` varchar(6) NOT NULL,
  `push` tinyint(1) NOT NULL DEFAULT 0,
  `greater_or_lower` tinyint(1) DEFAULT NULL,
  `watchValue` decimal(10,2) unsigned DEFAULT NULL,
  `sent` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_index` (`user_id`,`symbol`),
  KEY `symbol` (`symbol`),
  CONSTRAINT `Watching_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`),
  CONSTRAINT `Watching_ibfk_2` FOREIGN KEY (`symbol`) REFERENCES `Stocks` (`symbol`)
);

CREATE TABLE IF NOT EXISTS `Commissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rate` decimal(4,2) unsigned NOT NULL,
  `amount` decimal(10,2) unsigned NOT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `Trade` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `symbol` varchar(6) NOT NULL,
  `commission_id` int(10) unsigned DEFAULT NULL,
  `shares` int(10) NOT NULL,
  `expected_shares` int(10) unsigned NOT NULL,
  `stock_data_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `symbol` (`symbol`),
  KEY `commission_id` (`commission_id`),
  KEY `Trade_ibfk_4` (`stock_data_id`),
  CONSTRAINT `Trade_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`),
  CONSTRAINT `Trade_ibfk_2` FOREIGN KEY (`symbol`) REFERENCES `Stocks` (`symbol`),
  CONSTRAINT `Trade_ibfk_3` FOREIGN KEY (`commission_id`) REFERENCES `Commissions` (`id`),
  CONSTRAINT `Trade_ibfk_4` FOREIGN KEY (`stock_data_id`) REFERENCES `Stock_Data` (`id`)
);

CREATE TABLE IF NOT EXISTS `Portfolio` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `symbol` varchar(6) NOT NULL,
  `last_trade_id` int(10) unsigned NOT NULL,
  `initial_trade_id` int(10) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `initial_shares` int(10) unsigned NOT NULL,
  `held_shares` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_index` (`user_id`,`symbol`),
  KEY `user_id` (`user_id`),
  KEY `symbol` (`symbol`),
  KEY `last_trade_id` (`last_trade_id`),
  KEY `initial_trade_id` (`initial_trade_id`),
  CONSTRAINT `Portfolio_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`),
  CONSTRAINT `Portfolio_ibfk_2` FOREIGN KEY (`symbol`) REFERENCES `Stocks` (`symbol`),
  CONSTRAINT `Portfolio_ibfk_3` FOREIGN KEY (`last_trade_id`) REFERENCES `Trade` (`id`),
  CONSTRAINT `Portfolio_ibfk_4` FOREIGN KEY (`initial_trade_id`) REFERENCES `Trade` (`id`)
);

CREATE TABLE IF NOT EXISTS `Currencies` (
  `symbol` varchar(6) NOT NULL,
  PRIMARY KEY (`symbol`)
);

CREATE TABLE IF NOT EXISTS `ExchangeRates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(6) NOT NULL,
  `destination` varchar(6) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `ExchangeRates_ibfk_1` FOREIGN KEY (`source`) REFERENCES `Currencies` (`symbol`),
  CONSTRAINT `ExchangeRates_ibfk_2` FOREIGN KEY (`destination`) REFERENCES `Currencies` (`symbol`)
);
