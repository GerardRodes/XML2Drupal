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

  public function import($xml, $structure, $item_suffix = false) {
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
    $translations = 0;
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

      $language = "und";
      if (array_key_exists("language", $structure)) {
        if (array_key_exists("xml_tag", $structure["language"])) {
          $language = trim($item->$structure["language"]["xml_tag"]);
        } else if (array_key_exists("default", $structure["language"])) {
          $language = trim($structure["language"]["default"]);
        }
      }

      array_push($items_ids, trim($item_id));
      if (!array_key_exists(trim($item_id), $correct_key_items)) {
        $correct_key_items[trim($item_id)] = array();
      } else {
        $translations += 1;
      }
      $correct_key_items[trim($item_id)][$language] = $item;
      $index += 1;
    }
    $items = $correct_key_items;
    $total_items = sizeof($correct_key_items);
    $list_of_items_string = '';
    foreach ($correct_key_items as $id => $langs) {
      $list_of_items_string .= $id.'('.implode(", ",array_keys($langs)).') - ';
    }
    $this->event("List of items to import: ".$list_of_items_string);
    $this->event($translations." of them are translations.");
    /*--------------------------*/
    // 5 - Fetchs at data base for the nodes with a value (at the field
    //     specified as id existing) on the xml
    /*--------------------------*/
    $existing_nodes = array();
    if ($items_ids) {
      $existing_nodes = db_select($table_name, 'n')
        ->fields('n', array('entity_id', $column_name))
        ->condition('bundle', $structure["type"], '=')
        ->condition($column_name, $items_ids, 'IN')
        ->execute()
        ->fetchAllAssoc($column_name);
    }

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
    $succeed_nids = array();
    foreach ($nodes_to_create as $item_data) {
      $this->event("-----------------------------------------------------------------");
      $this->event($index.'/'.$total_items);
      $this->event("Creating node: ".$item_data["id"]);
      $nid = $this->create_node($item_data, $structure, $index, $item_suffix);
      $this->event("-----------------------------------------------------------------".PHP_EOL);

      if ($nid) {
        array_push($succeed_nids, $nid);
      }
    }

    foreach ($nodes_to_update as $item_data) {
      $this->event("-----------------------------------------------------------------");
      $this->event($index.'/'.$total_items);
      $this->event("Updating node: ".$item_data["id"]);
      $nid = $this->update_node($item_data, $structure, $index, $item_suffix);
      $this->event("-----------------------------------------------------------------".PHP_EOL);

      if ($nid) {
        array_push($succeed_nids, $nid);
      }
    }

    $this->event("Import finished");

    return $succeed_nids;
  }

  public function load_xml() {
    $this->event("Loading xml...");
    $context  = stream_context_create(array('http' => array('header' => 'Accept: application/xml')));
    if ($xml = file_get_contents($this->api_url, false, $context)) {
      if ($this->xml = simplexml_load_string($xml)) {
        $this->event("XML loaded");
        return $this->xml;
      } else {
        $this->event("Failed parsing xml.");
      }
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

  public function create_node($item_data, $structure, &$index, $item_suffix = false) {
    $node = entity_create("node", array("type" => $structure["type"]));
    $node->uid = $this->user->uid;
    $emw_node = entity_metadata_wrapper("node", $node);
    $succeed = $this->emw_set_fields($emw_node, $item_data["item"], $structure, $index, $item_data["id"], $item_suffix);

    return ($succeed ? $emw_node->getIdentifier() : false);
  }

  public function update_node($item_data, $structure, &$index, $item_suffix = false) {
    $node = node_load($item_data["nid"]);
    $emw_node = entity_metadata_wrapper("node", $node);
    $succeed = $this->emw_set_fields($emw_node, $item_data["item"], $structure, $index, $item_data["id"], $item_suffix);

    return ($succeed ? $emw_node->getIdentifier() : false);
  }

  private function emw_set_fields(&$emw_node, $item_bundle, $structure, &$index, $id, $item_suffix = false) {

    $translating = false;
    foreach ($item_bundle as $language => $item) {
      $msg = '';
      $status = '';
      $field_id = $structure['id'];
      $fields = array_diff_key($structure, array("type" => "", "xml_tag" => "", "id" => "", "language" => "", "menu_link" => ""));
      $vars = array("{id}" => $id);
      $translation = false;
      try{
        $actual_lang = $emw_node->language->value();
        if (strcmp($language,"und") == 0) {
          $this->event("Content not translatable.");
          $translating = false;
        } else if(strcmp($actual_lang, $language) == 0 || (strcmp($actual_lang, "und") == 0 && strcmp($language, "und") != 0) ){
          $emw_node->language->set($language);
          $this->event("Language set to ".$language);
          $translating = false;
        } else  {
          $this->event("Original language is ".$actual_lang);
          $this->event("Translating to ".$language);
          $translation_handler = entity_translation_get_handler('node', $emw_node->raw());
          $translating = true;
          $translation = array(
            'translate' => 0,
            'status' => 1,
            'language' => $language,
            'source' => $actual_lang
          );
        }
      } catch (EntityMetadataWrapperException $exc) {
        $this->event("EntityMetadataWrapper exception setting language in ".__FUNCTION__."()".PHP_EOL.$exc->getTraceAsString());
      }

      foreach ($fields as $field_name => $field_data) {
        $status = 'ok';
        $translatable_field = false;
        $this->event("Setting field: ".$field_name);
        if ($field_name == $field_id) {
          $field_value = $id;
        } else if (isset($field_data["xml_tag"])) {
          $this->event("  from \"".$field_data["xml_tag"]."\"");
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

        if (strcmp($language, "und") != 0 && isset($field_data["translate"]) && $field_data["translate"]) {
          $translatable_field = true;
          $this->event('This field is translatable, setting language specific values for "'.$language.'".');
        }

        if ($translating && $translatable_field || !$translating) {
          try {
            if (!is_array($field_data["type"])) {
              $field_data["type"] = array($field_data["type"]);
            }

            foreach ($field_data["type"] as $type) {
              switch ($type) {
                case 'string':
                  if ($field_value != ""){
                    if ($field_name == "title" && $item_suffix || $field_name == "title_field" && $item_suffix) {
                      $field_value = $item_suffix.$field_value;
                      $this->event('Adding suffix '.var_dump($item_suffix).', result: '.$field_value);
                    }

                    if ($translatable_field) {
                      try {
                        $emw_node->language($language)->$field_name->set($field_value);
                      } catch (Exception $exc) {
                        $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                      }
                    } else if (!$translating) {
                      $emw_node->$field_name->set($field_value);
                    }
                  }
                  break;

                case 'textarea':
                  if(is_array($field_value)){
                    $final_value = "";
                    foreach ($field_value as $value) {
                      $final_value .= $value.PHP_EOL.PHP_EOL;
                    }

                    $field_value = $final_value;
                  }

                  if ($translatable_field) {
                    try {
                      $emw_node->language($language)->$field_name->set(array("value" => $field_value));
                    } catch (Exception $exc) {
                      $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                    }
                  } else  if (!$translating) {
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
                      if ($translatable_field) {
                        try {
                          $emw_node->language($language)->$field_name->set(array(
                            "value" => $dates[0]->format('Y-m-d H:i:s'),
                            "value2" => $dates[1]->format('Y-m-d H:i:s')
                          ));
                        } catch (Exception $exc) {
                          $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                        }
                      } else if (!$translating) {
                        $emw_node->$field_name->set(array(
                            "value" => $dates[0]->format('Y-m-d H:i:s'),
                            "value2" => $dates[1]->format('Y-m-d H:i:s')
                          ));
                      }
                    }
                  } else {
                    $date_value = DateTime::createFromFormat($field_data["format"], $field_value);
                    if ($translatable_field) {
                      try {
                        $emw_node->language($language)->$field_name->set($date_value->getTimestamp());
                      } catch (Exception $exc) {
                        $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                      }
                    } else if (!$translating) {
                      $emw_node->$field_name->set($date_value->getTimestamp());
                    }
                  }
                  break;

                case 'node_creation':
                  $m_structure = $field_data["structure"];
                  $items_parent = $field_data["xml_tag"];
                  $item_suffix = false;

                  if ( (isset($field_data['translate']) && $field_data['translate'] && $translating) || !$translating ) {
                    if (isset($field_data["item_suffix"])) {
                      $item_suffix = strtr($field_data["item_suffix"], $vars);
                    }

                    $nids_to_reference = $this->import($item->$items_parent, $m_structure, $item_suffix);
                    $field_value = $nids_to_reference;
                  }
                  break;

                case 'node_reference':
                  if ($translatable_field) {
                    try {
                      $emw_node->language($language)->$field_name->set($field_value);
                    } catch (Exception $exc) {
                      $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                    }
                  } else if (!$translating) {
                    $emw_node->$field_name->set($field_value);
                  }
                  break;

                case '0_or_1':
                  if (is_string($field_value)) {
                    if ($field_value == "0" || $field_value == "1") {
                      $field_value = (int)$field_value;
                    } else {
                      $field_value = strtolower(trim($field_value));
                      if (strcmp($field_value, "true") == 0) {
                        $field_value = 1;
                      } else if (strcmp($field_value, "false") == 0) {
                        $field_value = 0;
                      }
                    }
                  }

                  if ($translatable_field) {
                    try {
                      $emw_node->language($language)->$field_name->set($field_value);
                    } catch (Exception $exc) {
                      $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                    }
                  } else if (!$translating) {
                    $emw_node->$field_name->set($field_value);
                  }
                  break;

                case 'file':
                  $timestamp = round(microtime(true) * 1000);
                  $url = trim($field_value);
                  $ext = pathinfo($url, PATHINFO_EXTENSION);

                  $source = $url;
                  $ch = curl_init();
                  curl_setopt($ch, CURLOPT_URL, $source);
                  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                  curl_setopt($ch, CURLOPT_SSLVERSION,3);
                  $data = curl_exec ($ch);
                  $error = curl_error($ch); 
                  curl_close ($ch);

                  $filename = $id.".".$ext;
                  $destination = "./tmp/".$filename;
                  $file = fopen($destination, "w+");
                  fputs($file, $data);
                  fclose($file);

                  $filepath = drupal_realpath($destination);
                  $public = 'public://';
                  $file_folder = '/opt/drupal7/spactiva/sites/default/files/cron';
                  $uri = file_unmanaged_move($filepath, $file_folder, FILE_EXISTS_REPLACE);

                  $uri = str_replace("/opt/drupal7/spactiva/sites/default/files/","public://",$uri);

                  $file = new stdClass();
                  $file->fid = NULL;
                  $file->uri = $uri;
                  $file->filename = drupal_basename($uri);
                  $file->filemime = file_get_mimetype($file->uri);
                  $file->uid = $this->user->uid;
                  $file->status = FILE_STATUS_PERMANENT;
                  $existing_files = file_load_multiple(array(), array('uri' => $uri));
                  if (count($existing_files)) {
                    $existing = reset($existing_files);
                    $file->fid = $existing->fid;
                    $file->filename = $existing->filename;
                  }
                  $file_saved = file_save($file);

                  try{
                    if ($translatable_field) {
                      try {
                        $emw_node->language($language)->$field_name->file->set($file_saved);
                      } catch (Exception $exc) {
                        $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                      }
                    } else if (!$translating) {
                      $emw_node->$field_name->file->set($file_saved);
                    }
                    $field_value = $file_saved;
                  } catch (Exception $exc) {
                    $this->event("File save exception, maybe you are running from console");
                  }

                  break;

                case 'term_reference':
                  $tid = null;
                  $term = null;

                  if (!is_array($field_value)) {
                    $field_value = array($field_value);
                  }
                  $tids = array();
                  foreach ($field_value as $term_name) {
                    if (isset($field_data["vocabulary"])) {
                      $term = array_shift(array_values(taxonomy_get_term_by_name($term_name, $field_data["vocabulary"])));

                      if (isset($term)) {
                        $tid = $term->tid;
                      } else {
                        $this->event("term \"".$term_name."\" not found at vocabulary \"".$field_data["vocabulary"]."\", creating...");
                        $vocabulary = taxonomy_vocabulary_machine_name_load($field_data["vocabulary"]);

                        if ($vocabulary != NULL) {
                          $new_term = entity_create('taxonomy_term', array(
                            "name" => $term_name,
                            "vid"  => $vocabulary->vid
                            ));
                          taxonomy_term_save($new_term);
                          $tid = $new_term->tid;
                        } else {
                          $this->event("Vocabulary \"".$field_data["vocabulary"]."\" not found");
                        }
                      }

                      array_push($tids, $tid);
                    } else {
                      $term = array_shift(array_values(taxonomy_get_term_by_name($term_name)));

                      if (isset($term)) {
                        $tid = $term->tid;
                        array_push($tids, $tid);
                      } else {
                        $this->event("term \"".$term_name."\" not found");
                      }
                    }
                  }
                  if (sizeof($tids) > 0) {
                    try{
                      if ($translatable_field) {
                        try {
                          $emw_node->language($language)->$field_name->file->set($tids[0]);
                        } catch (Exception $exc) {
                          $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                        }
                      } else if (!$translating) {
                        $emw_node->$field_name->set($tids[0]);
                      }
                    } catch (EntityMetadataWrapperException $exc) {
                      $this->event("  field is multiple term reference");
                      if ($translatable_field) {
                        try {
                          $emw_node->language($language)->$field_name->file->set($tids);
                        } catch (Exception $exc) {
                          $this->event("Setting field specific language value exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
                        }
                      } else if (!$translating) {
                        $emw_node->$field_name->set($tids);
                      }
                    }
                  } else {
                    $this->event("No tids recollected: ".var_dump($tids));
                  }

                  $field_value = $tids;
                  break;

                default:
                  $status = 'not supported';
                  $msg = "Field type \"".$type."\" not supported";
                  break;
              }
            }
          } catch (EntityMetadataWrapperException $exc) {
            $status = "exception";
            $msg = "EntityMetadataWrapper exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString();
          }
        } else {
          $status = "not translatable";
        }


        switch ($status) {
          case 'ok':
            $this->event('  succeed!');
            break;

          case 'not translatable':
            $this->event('  Field '.$field_name.' is not translatable.');
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

      if ($translation) {
        $translation_handler->setTranslation($translation, $emw_node->value());
        $emw_node->save();
        pathauto_node_update_alias($emw_node->value(), 'update', $values = array ( 'language' => $language));
      } else {
        $emw_node->save();
      }

      $translating = true;
    }
    $index += 1;

    if ($status == 'exception') {
      $emw_node->delete();
      $this->event("Node creation failed, node is not going to be saved.");
      return false;
    } else {

      if (array_key_exists("menu_link", $structure)) {

        $nid = $emw_node->getIdentifier();

        if (!$nid) {
          $emw_node = $emw_node->save();
          $nid = $emw_node->getIdentifier();
        }

        $path = 'node/'.$nid;
        $menu_link = menu_link_get_preferred($path, $structure["menu_link"]["menu_name"]);

        if (!$menu_link) {
          $menu_link = array(
            'link_path' => $path,
            'link_title' => $emw_node->title->value(),
            'menu_name' => $structure["menu_link"]["menu_name"], // Menu machine name, for example: main-menu
            'weight' => 0,
            'language' => $language,
            'plid' => $structure["menu_link"]["parent"][$language], // Parent menu item, 0 if menu item is on top level
            'module' => 'menu',
            );
          try{
            $mlid = menu_link_save($menu_link);
            $this->event("Menu link created, mlid: ".$mlid);
          } catch (Exception $exc) {
            $this->event("Menu link save exception in ".__FUNCTION__."()".PHP_EOL.$exc->getMessage().PHP_EOL.$exc->getTraceAsString());
          }
        }

      }

      $emw_node->save();
      $this->event("Node created and stored.");
      return true;
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
