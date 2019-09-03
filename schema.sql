CREATE DATABASE `audit`;
USE `audit`;

CREATE TABLE `requestMethod` (
    `requestMethodID` INT(1) UNSIGNED NOT NULL,
    `requestMethod` VARCHAR(8) NOT NULL,
    PRIMARY KEY (`requestMethodID`)
);

INSERT INTO `requestMethod`
    (`requestMethodID`, `requestMethod`)
VALUES
   (1, 'GET'),
   (2, 'POST'),
   (3, 'PUT'),
   (4, 'PATCH'),
   (5, 'DELETE'),
   (6, 'HEAD'),
   (7, 'CONNECT'),
   (8, 'OPTIONS'),
   (9, 'TRACE')
;

CREATE TABLE `accessLog` (
    `accessLogID` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `requestTime` DATETIME NOT NULL,
    `requestUri` VARCHAR(255) NOT NULL,
    `requestMethod` INT(1) UNSIGNED NOT NULL,
    `requestParams` TEXT DEFAULT NULL,
    `responseTime` DATETIME DEFAULT NULL,
    `responseStatus` INT(3) UNSIGNED DEFAULT NULL,
    `response` TEXT DEFAULT NULL,
    PRIMARY KEY (`accessLogID`),
    FOREIGN KEY (`requestMethod`) REFERENCES `requestMethod`(`requestMethodID`)
);
