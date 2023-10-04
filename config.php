<?php

class DbConn
  { // class for db connection
      private $connection;
      private static $instance;

      private $_host = DBHOST;
      private $_username = DBUSR ;
      private $_password = DBPWD;
      private $_database = DBSCHEMA;
      private $options = [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET CHARACTER SET utf8"
      ];


      public static function getInstance()
      {
          if (!self::$instance) {
              self::$instance = new self();
          }
          return self::$instance;
      }

      private function __construct()
      {
          try {
              $this->connection  = new PDO("mysql:host=$this->_host;dbname=$this->_database", $this->_username, $this->_password, $this->options);
          } catch (PDOException $e) {
              echo $e->getMessage();
          }
      }

      public function getConnection()
      {
        return $this->connection;
      }
  }
