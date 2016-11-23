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
  "language"                  => array("default" => "ca"),
  "field_api_id"              => array("xml_tag" => "id",                                     "type" => "string"),
  "field_a_qui_s_adre_a"      => array("xml_tag" => "AdresatA",                               "type" => "string"),
  "field_tematica"            => array("xml_tag" => "Agrupacio",                              "type" => "term_reference"),
  "body"                      => array("xml_tag" => array("ContingutAcF1", "ContingutAcF2"),  "type" => "textarea"),
  "field_data_d_inici"        => array("xml_tag" => "DataIni",                                "type" => "date",           "format" => "Y-m-d\TH:i:s"),
  "field_durada"              => array("xml_tag" => "Durada",                                 "type" => "string"),
  "field_llocs"               => array("xml_tag" => "Lloc",                                   "type" => "term_reference"),
  "title"                     => array("xml_tag" => "Nom",                                    "type" => "string"),
  "field_sessions"            => array("xml_tag" => "sessions",                               "type" => "multiple",       "structure" => array(
      "type"                => "curs_sessi_",
      "xml_tag"             => "Sessions",
      "id"                  => "field_api_id",
      "title"               => array("custom" => "Sessió {index}",              "type" => "string"),
      "field_data_d_inici"  => array("xml_tag" => "data",                       "type" => "date", "format" => "Y-m-d\TH:i:s"),
      "field_horari"        => array("xml_tag" => array("horaInici", "horaFi"), "type" => "date", "format" => "Y-m-d\TH:i:s"),
      "field_ubicacio"      => array("xml_tag" => "ubicacio",                   "type" => "string"),
      "field_api_id"        => array("xml_tag" => array("{index}", "data"),     "type" => "string")
    ))
  ));
$handler->login("cron", "I01M2ec}$#2#P9b");
$handler->import();