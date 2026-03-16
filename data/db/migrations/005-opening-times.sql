-- Seasonal opening times (#84)

CREATE TABLE IF NOT EXISTS `bs_squares_opening_times` (
  `stid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sid` int(10) unsigned DEFAULT NULL COMMENT 'NULL = all squares',
  `priority` int(10) unsigned NOT NULL,
  `date_start` date NOT NULL,
  `date_end` date NOT NULL,
  `time_start` time NOT NULL,
  `time_end` time NOT NULL,
  PRIMARY KEY (`stid`),
  KEY `sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `bs_squares_opening_times`
  ADD CONSTRAINT `bs_squares_opening_times_ibfk_1`
  FOREIGN KEY (`sid`) REFERENCES `bs_squares` (`sid`)
  ON DELETE CASCADE ON UPDATE CASCADE;