<?php

/*-------------------------------------------------------------------*/
// STRUCTURE MAP
// array(
//   'type' => 'content type name',
//   'xml_tag' => 'name of the xml tag which wraps the item attributes',
//   'field_name1' => array('xml_tag' => 'name of xml attribute 1'),
//   'field_name2' => array('xml_tag' => 'name of xml attribute 2'),
//   'field_name3' => array('xml_tag' => 'name of xml attribute 3')
//   );
/*-------------------------------------------------------------------*/
define('MAX_LOG_FILES', 30);

class XML2Drupal {
  
  private $api_url;
  private $user;
  private $xml;
  private $timezone;
  private $date;
  private $log_filename;
  private $log_folder;
  private $structure;
  private $items;

  function __construct($log_folder = "/ws/logs", $timezone = "Europe/Madrid", $log_format_date = "Y-m-d_H-i-s", $log_prefix = "log_") {
    $this->timezone = $timezone;
    date_default_timezone_set($this->timezone);
    $this->date = date($log_format_date, time());
    $this->api_url = $api_url;
    $this->log_filename = $log_prefix.$this->date.".txt";
    $this->log_folder = DRUPAL_ROOT.$log_folder;
    $this->items = array();

    $this->init_log();
  }



  /************************************************************/
  // Initilization methods
  /***********************************************************/

  public function import() {
    $this->load_xml();

    $this->event("Looking for items...");
    $this->find_items($this->xml);
    $total_items = sizeof($this->items);
    $this->event($total_items." items found");

    $processed_items = 0;
    $field_id = $this->structure["id"];
    $table_name = "field_data_".$field_id;
    $items_ids = array();

    foreach ($this->items as $item) {
      $item_id = $item->{$this->structure[$field_id]["xml_tag"]};
      array_push($items_ids, trim($item_id));
    }
    $this->event("List of items to import: ".implode(", ",$items_ids));

    $existing_nodes = db_select($table_name, 'n')
      ->fields('n', array('entity_id'))
      ->condition('field_api_id_value', $items_ids, 'IN')
      ->execute()
      ->fetchAssoc();

    // foreach ($this->items as $item) {
    //   $processed_items += 1;
    //   $this->event($processed_items."/".$total_items." items processed");

    //   $item_id = $item->{$this->structure[$field_id]["xml_tag"]};

    //   if ($this->create_node($item)) {

    //   }

    // }

  }

  public function load_xml() {
    $this->event("Loading xml...");
    if ($this->xml = simplexml_load_file($this->api_url)) {
      return true;
    } else {
      $this->event("Failed loading xml at \"{$this->api_url}\"");
    }
  }

  private function find_items($xml) {
    foreach ($xml->children() as $child) {
      if (strcmp($child->getName(), $this->structure['xml_tag']) == 0 ){
        array_push($this->items, $child);
      }

      if ( sizeof($child->children()) > 0 ) {
        $this->find_items($child);
      }
    }
  }


  /************************************************************/
  // Drupal handling methods
  /***********************************************************/

  public function login($user, $pass){
    if (user_authenticate($user, $pass)) {
      $this->user = user_load_by_name($user);
      $form_state = array("uid" => $this->user->uid);
      user_login_submit(array(), $form_state);

      $this->event("Logged in as \"{$user}\"");
      return true;
    } else {
      $this->event("Login as user \"{$user}\" failed");
    }
  }

  public function create_node($item) {
    $node = entity_create("node", array("type" => $this->structure["type"]));
    $node->uid = $this->user->uid;
    $emw_node = entity_metadata_wrapper("node", $node);

    foreach ($this->structure as $field_name => $field_data) {
      switch ($field_data["type"]) {
        case 'string':
          $emw_node->$field_name = $item->{$field_data["xml_tag"]};
          break;
        
        default:
          $this->event("Field type \"".$field_data["type"]."\" not supported");
          break;
      }
    }
  }



  /************************************************************/
  // Setters
  /***********************************************************/

  public function set_api_url($url){
    $this->api_url = $url;
    $this->event("Setted api url: {$url}");
  }

  public function set_structure($structure){
    $this->structure = $structure;
  }



  /************************************************************/
  // Getters
  /***********************************************************/

  public function get_simplexml(){
    return $this->xml;
  }

  public function get_items(){
    return $this->items;
  }



  /************************************************************/
  // Log methods
  /***********************************************************/

  private function init_log(){
    $old_logs = array_diff(scandir($this->log_folder), array('.', '..'));
    sort($old_logs);

    if (sizeof($old_logs) > MAX_LOG_FILES) {
      for($i = sizeof($old_logs) - MAX_LOG_FILES; $i >= 0; $i--){
        unlink($this->log_folder."/".$old_logs[$i]);
      }
    }

    $this->event("Initiating import, saving log as \"{$this->log_filename}\"");
  }

  private function event($msg) {
    echo $msg.PHP_EOL;
    $file = fopen($this->log_folder."/".$this->log_filename, "a+") or die("Unable to open file!");
    fwrite($file, $msg.PHP_EOL);
    fclose($file);
  }

}