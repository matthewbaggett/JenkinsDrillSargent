<?php

require_once("vendor/autoload.php");

$database = new \Thru\ActiveRecord\DatabaseLayer(
  [
    'db_type' => 'Sqlite',
    'db_file' => 'drillsargent.sqlite',
  ]
);
