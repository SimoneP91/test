CREATE TABLE `node_tree` (
  `idNode` int(11) NOT NULL AUTO_INCREMENT,
  `level` int(11) DEFAULT NULL,
  `iLeft` int(11) DEFAULT NULL,
  `iRight` int(11) DEFAULT NULL,
  PRIMARY KEY (`idNode`),
  KEY `iLeft` (`iLeft`),
  KEY `iRight` (`iRight`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE node_tree_names(
    idNode INT NOT NULL,
    language ENUM ('english','italian'),
    nodeName varchar(100) not null,
    CONSTRAINT idNode
    FOREIGN KEY (idNode) REFERENCES node_tree(idNode),
    KEY `language` (`language`),
    KEY `nodeName` (`nodeName`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4;
