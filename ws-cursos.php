<?php

if (!isset($_SERVER["REMOTE_ADDR"])) {
  $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
}

if (!isset($_SERVER["HTTP_HOST"])) {
  $_SERVER["HTTP_HOST"] = "spactiva.dev";
}

define("DRUPAL_ROOT", "/opt/drupal7/spactiva");
require_once DRUPAL_ROOT . "/includes/bootstrap.inc";
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

require_once "/opt/drupal7/spactiva/ws-cursos/XML2Drupal.php";

$handler = new XML2Drupal($log_folder = "/ws-cursos/logs");
$handler->set_api_url("/opt/drupal7/spactiva/ws-cursos/xml_examples/FormacioPublica.xml");
$handler->set_structure(array(
  "type"                      => "curs",
  "xml_tag"                   => "AcF",
  "id"                        => "field_api_id",
  "field_api_id"              => array("xml_tag" => "id",             "type" => "string"),
  "field_a_qui_s_adre_a"      => array("xml_tag" => "AdresatA",       "type" => "string"),
  "field_tematica"            => array("xml_tag" => "Agrupacio",      "type" => "string"),
  "body"                      => array("xml_tag" => "ContingutAcF1",  "type" => "string"),
  "field_informaci_d_inter_s" => array("xml_tag" => "ContingutAcF2",  "type" => "string"),
  "field_data_d_inici"        => array("xml_tag" => "DataIni",        "type" => "date"),
  "field_durada"              => array("xml_tag" => "Durada",         "type" => "string"),
  "field_llocs"               => array("xml_tag" => "Lloc",           "type" => "string"),
  "title_field"               => array("xml_tag" => "Nom",            "type" => "string")
  ));
$handler->login("cron", "I01M2ec}$#2#P9b");
$handler->import();