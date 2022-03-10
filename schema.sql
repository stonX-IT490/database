CREATE DATABASE stonx DEFAULT CHARACTER SET = 'utf8mb4';

USE stonx;

CREATE TABLE IF NOT EXISTS `Users` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` TINYTEXT NOT NULL,
    `password` TINYTEXT NOT NULL,
    `opened_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `first_name` TINYTEXT NOT NULL,
    `last_name` TINYTEXT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE (`email`)
);

CREATE TABLE IF NOT EXISTS `Balance` (
    `user_id` int UNSIGNED NOT NULL,
    `amount` decimal(10, 2) UNSIGNED default 0.00,
    `last_updated` TIMESTAMP default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES Users(`id`)
);

CREATE TABLE IF NOT EXISTS `Transactions` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int UNSIGNED NOT NULL,
    `created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `amount` decimal(10, 2) UNSIGNED NOT NULL,
    `expected_balance` decimal(10, 2) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES Users(`id`)
);

CREATE TABLE IF NOT EXISTS `Stocks` (
    `symbol` VARCHAR(6) NOT NULL,
    `company_name` TEXT NOT NULL,
    PRIMARY KEY (`symbol`)
);

CREATE TABLE IF NOT EXISTS `Stock_Data` (
    `symbol` VARCHAR(6) NOT NULL,
    `created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `value` decimal(10, 2) UNSIGNED NOT NULL,
    FOREIGN KEY (`symbol`) REFERENCES Stocks(`symbol`)
);

CREATE TABLE IF NOT EXISTS `Watching` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `symbol` VARCHAR(6) NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES Users(`id`),
    FOREIGN KEY (`symbol`) REFERENCES Stocks(`symbol`)
);

CREATE TABLE IF NOT EXISTS `Watching_Push` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `symbol` VARCHAR(6) NOT NULL,
    greater_or_lower BOOLEAN NOT NULL,
    `watchValue` decimal(10, 2) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES Users(`id`),
    FOREIGN KEY (`symbol`) REFERENCES Stocks(`symbol`)
);

CREATE TABLE IF NOT EXISTS `Commissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `trade_id` INT UNSIGNED NOT NULL,
    `rate` decimal(4, 2) UNSIGNED NOT NULL,
    `amount` decimal(10, 2) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `Trade` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `symbol` VARCHAR(6) NOT NULL,
    `commission_id` INT UNSIGNED,
    `shares` INT UNSIGNED NOT NULL,
    `expected_shares` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES Users(`id`),
    FOREIGN KEY (`symbol`) REFERENCES Stocks(`symbol`),
    FOREIGN KEY (`commission_id`) REFERENCES Commissions(`id`)
);

CREATE TABLE IF NOT EXISTS `Portfolio` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `symbol` VARCHAR(6) NOT NULL,
    `last_trade_id` INT UNSIGNED NOT NULL,
    `initial_trade_id` INT UNSIGNED NOT NULL,
    `created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `initial_shares` INT UNSIGNED NOT NULL,
    `held_shares` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES Users(`id`),
    FOREIGN KEY (`symbol`) REFERENCES Stocks(`symbol`),
    FOREIGN KEY (`last_trade_id`) REFERENCES Trade(`id`),
    FOREIGN KEY (`initial_trade_id`) REFERENCES Trade(`id`)
);

ALTER TABLE `Commissions` ADD FOREIGN KEY (`trade_id`) REFERENCES Trade(`id`);
