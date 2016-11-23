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

    $this->init_log();
  }



  /************************************************************/
  // Initilization methods
  /***********************************************************/

  public function import($xml, $structure) {
    /*--------------------------*/
    // 1 - Loads the XML
    /*--------------------------*/
    $simple_xml = (isset($xml) ? $xml : $this->load_xml());
    $structure  = (isset($structure) ? $structure : $this->structure);

    /*--------------------------*/
    // 2 - Finds the items with the specified tag at "xml_tag"
    /*--------------------------*/
    $items = array();
    $this->event("Looking for items...");
    $this->find_items($simple_xml, $structure['xml_tag'], $items);
    $total_items = sizeof($items);
    $this->event($total_items." items found");

    /*--------------------------*/
    // 3 - Setting useful vars
    /*--------------------------*/
    $processed_items = 0;
    $field_id = $structure["id"];
    $xml_tag_id = $structure[$field_id]["xml_tag"];
    $table_name = "field_data_".$field_id;
    $column_name = $field_id."_value";
    $items_ids = array();

    /*--------------------------*/
    // 4 - Fetch values of the field used as id on xml
    /*--------------------------*/
    $index = 1;
    $correct_key_items = array();
    foreach ($items as $item) {
      if (is_array($xml_tag_id)) {
        $item_id = '';

        foreach ($xml_tag_id as $value) {
          if ($item_id != '') {
            $item_id .= "-";
          }
          $item_id .= ($value == "{index}" ? $index : $item->$value);
        }
      } else {
        $item_id = $item->$xml_tag_id;
      }
      array_push($items_ids, trim($item_id));
      $correct_key_items[trim($item_id)] = $item;
      $index += 1;
    }
    $items = $correct_key_items;
    $this->event("List of items to import: ".implode(", ",$items_ids));

    /*--------------------------*/
    // 5 - Fetchs at data base for the nodes with a value (at the field
    //     specified as id existing) on the xml
    /*--------------------------*/
    $existing_nodes = db_select($table_name, 'n')
      ->fields('n', array('entity_id', $column_name))
      ->condition('bundle', $structure["type"], '=')
      ->condition($column_name, $items_ids, 'IN')
      ->execute()
      ->fetchAllAssoc($column_name);

    /*--------------------------*/
    // 6 - Filters the nodes as "node to create" or "node to update"
    /*--------------------------*/
    $nodes_to_create = array();
    $nodes_to_update = array();
    foreach ($items as $item_id => $item) {
      if (array_key_exists($item_id, $existing_nodes)) {
        array_push($nodes_to_update, array("nid" => $existing_nodes[$item_id]->entity_id, "item" => $item, "id" => $item_id));
      } else {
        array_push($nodes_to_create, array("item" => $item, "id" => $item_id));
      }
    }

    $this->event("Total items: ".$total_items.PHP_EOL."Items to create: ".sizeof($nodes_to_create).PHP_EOL."Items to update: ".sizeof($nodes_to_update));
    /*--------------------------*/
    // 7 - Creation and updation
    /*--------------------------*/
    $index = 1;
    foreach ($nodes_to_create as $item_data) {
      $this->event("-----------------------------------------------------------------");
      $this->event($index.'/'.$total_items);
      $this->event("Creating node: ".$item_data["id"]);
      $this->create_node($item_data, $structure, $index);
      $this->event("-----------------------------------------------------------------".PHP_EOL);
    }

    foreach ($nodes_to_update as $item_data) {
      $this->event("-----------------------------------------------------------------");
      $this->event($index.'/'.$total_items);
      $this->event("Updating node: ".$item_data["id"]);
      $this->update_node($item_data, $structure, $index);
      $this->event("-----------------------------------------------------------------".PHP_EOL);
    }

    $this->event("Import finished");
  }

  public function load_xml() {
    $this->event("Loading xml...");
    if ($this->xml = simplexml_load_file($this->api_url)) {
      return $this->xml;
    } else {
      $this->event("Failed loading xml at \"{$this->api_url}\"");
    }
  }

  private function find_items($xml, $tag, &$output) {
    foreach ($xml->children() as $child) {
      if (strcmp($child->getName(), $tag) == 0 ){
        array_push($output, $child);
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

  public function create_node($item_data, $structure, &$index) {
    $node = entity_create("node", array("type" => $structure["type"]));
    $node->uid = $this->user->uid;
    $emw_node = entity_metadata_wrapper("node", $node);
    $this->emw_set_fields($emw_node, $item_data["item"], $structure, $index, $item_data["id"]);
  }

  public function update_node($item_data, $structure, &$index) {
    $node = node_load($item_data["nid"]);
    $emw_node = entity_metadata_wrapper("node", $node);
    $this->emw_set_fields($emw_node, $item_data["item"], $structure, $index, $item_data["id"]);
  }

  private function emw_set_fields($emw_node, $item, $structure, &$index, $id) {
    $msg = '';
    $status = '';
    $field_id = $structure['id'];
    $fields = array_diff_key($structure, array("type" => "", "xml_tag" => "", "id" => "", "language" => ""));
    $vars = array("{id}" => $id);
    if (array_key_exists("language", $structure)) {
      $language = "";

      if (array_key_exists("xml_tag", $structure["language"])) {
        $language = trim($item->language["xml_tag"]);
      } else if (array_key_exists("default", $structure["language"])) {
        $language = trim($structure["language"]["default"]);
      }

      if ($language != ""){
        try{
          $emw_node->language->set($language);
          $this->event("Language set to ".$language);
        } catch (EntityMetadataWrapperException $exc) {
          $this->event("EntityMetadataWrapper exception setting language in ".__FUNCTION__."()".PHP_EOL.$exc->getTraceAsString());
        }
      }
    }

    foreach ($fields as $field_name => $field_data) {
      $status = 'ok';
      if ($field_name == $field_id) {
        $field_value = $id;
      } else if (isset($field_data["xml_tag"])) {
        $this->event("Setting \"".$field_name."\" from \"".$field_data["xml_tag"]."\"");
        $xml_tag = $field_data["xml_tag"];

        if(is_array($xml_tag)){
          $this->event("  Combining multiple tags:");
          $field_value = array();
          foreach ($xml_tag as $tag) {
            $this->event("    -> ".$tag);
            $value = "";
            if (array_key_exists($tag, $vars)) {
              $value = strtr($tag, $vars);
            } else {
              $value = trim($item->$tag);
            }
            array_push($field_value, $value);
          }

        } else {
          $field_value = trim($item->$xml_tag);
        }
      } else if (isset($field_data["custom"])) {
        $field_value = str_replace("{index}",$index,$field_data["custom"]);
        $this->event("Setting custom \"".$field_name."\" from \"".$field_data["custom"]."\", result: ".$field_value);
      }


      try {
        switch ($field_data["type"]) {
          case 'string':
            if ($field_value != ""){
              $emw_node->$field_name->set(trim($field_value));
            }
            break;

          case 'textarea':

            if(is_array($field_value)){
              $final_value = "";
              foreach ($field_value as $value) {
                $final_value .= $value.PHP_EOL.PHP_EOL;
              }
              $emw_node->$field_name->set(array("value" => $final_value));
            } else if ($field_value != ""){
              $emw_node->$field_name->set(array("value" => $field_value));
            }

            break;

          case 'date':
            if(is_array($field_value)){
              $dates = array();
              foreach ($field_value as $value) {
                array_push($dates, DateTime::createFromFormat($field_data["format"], $value));
              }

              if (sizeof($dates) == 2){
                $emw_node->$field_name->set(array(
                    "value" => $dates[0]->format('Y-m-d H:i:s'),
                    "value2" => $dates[1]->format('Y-m-d H:i:s')
                  ));
              }
            } else {
              $date_value = DateTime::createFromFormat($field_data["format"], $field_value);
              $emw_node->$field_name->set($date_value->getTimestamp());
            }
            break;

          case 'multiple':
            $m_structure = $field_data["structure"];
            $items_parent = $field_data["xml_tag"];
            $this->import($item->$items_parent, $m_structure);
            break;

          default:
            $status = 'not supported';
            $msg = "Field type \"".$field_data["type"]."\" not supported";
            break;
        }
      } catch (EntityMetadataWrapperException $exc) {
        $status = "exception";
        $msg = "EntityMetadataWrapper exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString();
      }


      switch ($status) {
        case 'ok':
          $this->event('  succeed!');
          break;

        case 'not supported':
          $this->event($msg);
          break;

        case 'exception':
          $this->event($msg);
          break 2;

        default:
          $this->event("Unknow status: ".$status);
          break;
      }
    }

    if ($status == 'exception') {
      $emw_node->delete();
      $this->event("Node creation failed, node is not going to be saved.");
      return false;
    } else {
      $emw_node->save();
      $this->event("Node created and stored.");
      return true;
    }

    $index += 1;
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



  /************************************************************/
  // Log methods
  /***********************************************************/

  private function init_log(){
    $old_logs = array_diff(scandir($this->log_folder), array('.', '..'));
    sort($old_logs);

    if (sizeof($old_logs) >= MAX_LOG_FILES) {
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