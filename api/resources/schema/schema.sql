CREATE TABLE `Player`  (
	Id INT auto_increment NOT NULL,
	Firstname varchar(100) NOT NULL,
	Name varchar(100) NOT NULL,
	`Member` TINYINT(1) NOT NULL,
	Gender ENUM('Man', 'Women') NOT NULL,
	BirthDate DATE NOT NULL,
	DoubleRanking INT NOT NULL,
	CONSTRAINT Player_pk PRIMARY KEY (Id)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
