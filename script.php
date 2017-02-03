<?php
require_once DRUPAL_ROOT . "/includes/bootstrap.inc";
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
require_once "./XML2Drupal.php";
$handler = new XML2Drupal($log_folder = "/logs");
$handler->set_api_url("http://xml.url");
$handler->set_structure(array(
  "type"                      => "curs",
  "xml_tag"                   => "AcF",
  "id"                        => "field_api_id",
  "menu_link"                 => array("menu_name" => "main-menu", "parent" => array("es" => 1313, "ca" => 1292)),
  "language"                  => array("xml_tag" => "Language"),
  "field_imatge_principal"    => array("xml_tag" => "Img",                                    "type" => "file"),
  "promote"                   => array("xml_tag" => "Destacat",                               "type" => "0_or_1"),
  "field_api_id"              => array("xml_tag" => "id",                                     "type" => "string"),
  "field_a_qui_s_adre_a"      => array("xml_tag" => "AdresatA",                               "type" => "string", "translate" => true),
  "field_tematica"            => array("xml_tag" => "Agrupacio",                              "type" => "term_reference", "vocabulary" => "tematiques_cursos"),
  "field_url_inscripcio"      => array("xml_tag" => "url",                                    "type" => "string"),
  "body"                      => array("xml_tag" => array("ContingutAcF1", "ContingutAcF2"),  "type" => "textarea", "translate" => true),
  "field_data_d_inici"        => array("xml_tag" => "DataIni",                                "type" => "date",           "format" => "Y-m-d\TH:i:s"),
  "field_durada"              => array("xml_tag" => "Durada",                                 "type" => "string"),
  "field_llocs"               => array("xml_tag" => "Lloc",                                   "type" => "term_reference", "vocabulary" => "localitzacions_cursos"),
  "title"                     => array("xml_tag" => "Nom",                                    "type" => "string"),
  "title_field"               => array("xml_tag" => "Nom",                                    "type" => "string", "translate" => true),
  "field_sessions"            => array("xml_tag" => "sessions",                               "type" => array("node_creation","node_reference"),       "item_suffix" => "{id} - ",  "structure" => array(
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
$handler->login("user", "pass");
$handler->import();
