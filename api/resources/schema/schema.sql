CREATE TABLE `Player` (
	`Id` INT(11) NOT NULL AUTO_INCREMENT,
	`Firstname` VARCHAR(100) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`Name` VARCHAR(100) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`Member` TINYINT(1) NOT NULL,
	`Gender` ENUM('Man','Woman') NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`BirthDate` DATE NOT NULL,
	`DoubleRanking` INT(11) NOT NULL,
	`PlaysCompetition` TINYINT(1) NOT NULL DEFAULT '0',
	PRIMARY KEY (`Id`) USING BTREE
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
AUTO_INCREMENT=2
;




CREATE TABLE `Season` (
	`Id` INT(11) NOT NULL AUTO_INCREMENT,
	`Name` VARCHAR(50) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	PRIMARY KEY (`Id`) USING BTREE
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;

CREATE TABLE `Round` (
	`Id` INT(11) NOT NULL AUTO_INCREMENT,
	`Number` INT(11) NOT NULL,
	`Date` DATE NOT NULL,
	`AverageAbsent` DOUBLE NULL DEFAULT NULL,
	`SeasonId` INT(11) NOT NULL,
	`Calculated` TINYINT(1) NOT NULL,
	`DrawClosed` TINYINT(1) NOT NULL DEFAULT '0',
	PRIMARY KEY (`Id`) USING BTREE,
	INDEX `FK_Round_Season` (`SeasonId`) USING BTREE,
	CONSTRAINT `FK_Round_Season` FOREIGN KEY (`SeasonId`) REFERENCES `Season` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;


CREATE TABLE `Match` (
	`Id` INT(11) NOT NULL AUTO_INCREMENT,
	`RoundId` INT(11) NOT NULL,
	`Player1Id` INT(11) NOT NULL DEFAULT '0',
	`Player2Id` INT(11) NOT NULL DEFAULT '0',
	`Player3Id` INT(11) NOT NULL DEFAULT '0',
	`Player4Id` INT(11) NOT NULL DEFAULT '0',
	`Set1Home` INT(11) NULL DEFAULT NULL,
	`Set1Away` INT(11) NULL DEFAULT NULL,
	`Set2Home` INT(11) NULL DEFAULT NULL,
	`Set2Away` INT(11) NULL DEFAULT NULL,
	`Set3Home` INT(11) NULL DEFAULT NULL,
	`Set3Away` INT(11) NULL DEFAULT NULL,
	PRIMARY KEY (`Id`) USING BTREE,
	INDEX `FK_Match_Player_1` (`Player1Id`) USING BTREE,
	INDEX `FK_Match_Player_2` (`Player2Id`) USING BTREE,
	INDEX `FK_Match_Player_3` (`Player3Id`) USING BTREE,
	INDEX `FK_Match_Player_4` (`Player4Id`) USING BTREE,
	INDEX `FK_Match_Round` (`RoundId`) USING BTREE,
	CONSTRAINT `FK_Match_Player_1` FOREIGN KEY (`Player1Id`) REFERENCES `Player` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION,
	CONSTRAINT `FK_Match_Player_2` FOREIGN KEY (`Player2Id`) REFERENCES `Player` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION,
	CONSTRAINT `FK_Match_Player_3` FOREIGN KEY (`Player3Id`) REFERENCES `Player` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION,
	CONSTRAINT `FK_Match_Player_4` FOREIGN KEY (`Player4Id`) REFERENCES `Player` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION,
	CONSTRAINT `FK_Match_Round` FOREIGN KEY (`RoundId`) REFERENCES `Round` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;


CREATE TABLE `PlayerRoundStatistic` (
	`RoundId` INT(11) NOT NULL,
	`PlayerId` INT(11) NOT NULL,
	`Present` TINYINT(1) NOT NULL,
	`DrawnOut` TINYINT(1) NOT NULL,
	`Average` DOUBLE NULL DEFAULT NULL,
	PRIMARY KEY (`RoundId`, `PlayerId`) USING BTREE,
	INDEX `FK_RoundPlayer_Player` (`PlayerId`) USING BTREE,
	CONSTRAINT `FK_RoundPlayer_Player` FOREIGN KEY (`PlayerId`) REFERENCES `Player` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION,
	CONSTRAINT `FK_RoundPlayer_Round` FOREIGN KEY (`RoundId`) REFERENCES `Round` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;



CREATE TABLE `PlayerSeasonStatistic` (
	`Id` INT(11) NOT NULL AUTO_INCREMENT,
	`SeasonId` INT(11) NOT NULL DEFAULT '0',
	`PlayerId` INT(11) NOT NULL DEFAULT '0',
	`BasePoints` DOUBLE NOT NULL DEFAULT '0',
	`SetsPlayed` INT(11) NOT NULL DEFAULT '0',
	`SetsWon` INT(11) NOT NULL DEFAULT '0',
	`PointsPlayed` INT(11) NOT NULL DEFAULT '0',
	`PointsWon` INT(11) NOT NULL DEFAULT '0',
	`RoundsPresent` INT(11) NOT NULL DEFAULT '0',
	`MatchesPlayed` INT(11) NOT NULL DEFAULT '0',
	PRIMARY KEY (`Id`) USING BTREE,
	INDEX `FK_SeasonPlayer_Season` (`SeasonId`) USING BTREE,
	INDEX `FK_SeasonPlayer_Player` (`PlayerId`) USING BTREE,
	CONSTRAINT `FK_SeasonPlayer_Player` FOREIGN KEY (`PlayerId`) REFERENCES `Player` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION,
	CONSTRAINT `FK_SeasonPlayer_Season` FOREIGN KEY (`SeasonId`) REFERENCES `Season` (`Id`) ON UPDATE NO ACTION ON DELETE NO ACTION
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;
