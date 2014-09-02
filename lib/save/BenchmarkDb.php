<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


/**
 * 
 */
require_once(dirname(dirname(__FILE__)) . '/util.php');
date_default_timezone_set('UTC');

class BenchmarkDb {
  
  /**
   * config file path
   */
  const BENCHMARK_DB_CONFIG_FILE = '~/.ch_benchmark';
  
  /**
   * an optional archiver
   */
  protected $archiver;
  
  /**
   * tracks artifacts saved using the saveArtifact method - a hash indexed by
   * column name where the value is the artifact URL
   */
  private $artifacts = array();
  
  /**
   * the directory where CSV files will be written to
   */
  protected $dir;
  
  /**
   * db options
   */
  protected $options;
  
  /**
   * data rows indexed by table name
   */
  private $rows = array();
  
  /**
   * stores references to schemas retrieved from getSchema
   */
  private $schemas = array();
  
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  private function BenchmarkDb($options) {}
    
  /**
   * adds benchmark meta data to $row
   * @param array $row the row to add metadata to
   * @return void
   */
  private final function addBenchmarkMeta(&$row) {
    // find benchmark.ini
    if ($ini = get_benchmark_ini()) {
      if (isset($ini['meta-version'])) $row['benchmark_version'] = $ini['meta-version']; 
    }
  }
  
  /**
   * Adds a $row to $table
   * @param string $table 
   * @param array $row 
   * @return boolean
   */
  public final function addRow($table, $row) {
    $added = FALSE;
    if ($table && is_array($row)) {
      $this->addBenchmarkMeta($row);
      // add system meta information
      foreach(get_sys_info() as $k => $v) if (!isset($row[sprintf('meta_%s', $k)])) $row[sprintf('meta_%s', $k)] = $v;
      
      $added = TRUE;
      if (!isset($this->rows[$table])) $this->rows[$table] = array();
      $this->rows[$table][] = array_merge($this->artifacts, $row);
    }
    return $added;
  }
  
  /**
   * returns a BenchmarkDb object based on command line arguments. returns NULL
   * if there are any problems with the command line arguments
   * @return BenchmarkDb
   */
  public static function &getDb() {
    $db = NULL;
    $options = parse_args(array('db:', 'db_and_csv:', 'db_callback_header:', 'db_host:', 'db_name:', 'db_port:', 'db_pswd:', 'db_prefix:', 'db_user:', 'output:', 'remove:', 'store:', 'v' => 'verbose'), array('remove'), 'save_');
    merge_options_with_config($options, BenchmarkDb::BENCHMARK_DB_CONFIG_FILE);
    if (!isset($options['remove'])) $options['remove'] = array();
    // explode remove options specified in config
    if (!is_array($options['remove'])) {
      $remove = array();
      foreach(explode(',', $options['remove']) as $r) $remove[] = trim($r);
      $options['remove'] = $remove;
    }
    
    $impl = 'BenchmarkDb';
    if (isset($options['db'])) {
      switch($options['db']) {
        case 'bigquery':
          $impl .= 'BigQuery';
          break;
        case 'callback':
          $impl .= 'Callback';
          break;
        case 'mysql':
          $impl .= 'MySql';
          break;
        case 'postgresql':
          $impl .= 'PostgreSql';
          break;
        default:
          $err = '--db ' . $options['db'] . ' is not valid';
          break;
      }
      // invalid --db argument
      if (isset($err)) {
        print_msg($err, isset($options['verbose']), __FILE__, __LINE__, TRUE);
        return $db;
      }
    }
    if ($impl != 'BenchmarkDb') require_once(sprintf('%s/%s.php', dirname(__FILE__), $impl));
    
    $db = new $impl($options);
    $db->options = $options;
    if (!$db->validateDependencies()) $db = NULL;
    else if (!$db->validate()) $db = NULL;
    
    if ($db && isset($options['store'])) {
      require_once('BenchmarkArchiver.php');
      $db->archiver =& BenchmarkArchiver::getArchiver();
      if (!$db->archiver) $db = NULL;
    }
    
    return $db;
  }
  
  /**
   * returns the schema for the $table specified. return value is an array of
   * column/meta pairs
   * @param string $table the table to get the schema for
   * @return array
   */
  protected final function getSchema($table) {
    if (!isset($this->schemas[$table])) {
      $this->schemas[$table] = array();
      $files = array(sprintf('%s/schema/common.json', dirname(__FILE__)), sprintf('%s/schema/%s.json', dirname(__FILE__), $table));
      foreach($files as $file) {
        foreach(json_decode(file_get_contents($file), TRUE) as $col) {
          if (in_array($col['name'], $this->options['remove'])) continue;
          $this->schemas[$table][$col['name']] = $col;
        }
      }
      // remove steady state columns for the fio and wsat tables
      if ($table == 'fio' || $table == 'wsat') {
        foreach(array_keys($this->schemas[$table]) as $col) if (preg_match('/^ss_/', $col)) unset($this->schemas[$table][$col]);
      }
      ksort($this->schemas[$table]); 
    }
    return $this->schemas[$table];
  }
  
  /**
   * returns the actual table name to use for $table (applies --db_prefix)
   * @param string $table the base name of the table
   * @return string
   */
  protected final function getTableName($table) {
    $prefix = isset($this->options['db_prefix']) ? $this->options['db_prefix'] : 'block_storage_';
    return $prefix . $table;
  }
  
  /**
   * this method should be overriden by sub-classes to import CSV data into the 
   * underlying datastore. It should return TRUE on success, FALSE otherwise
   * @param string $table the name of the table to import to
   * @param string $csv the CSV file to import
   * @param array $schema the table schema
   * @return boolean
   */
  protected function importCsv($table, $csv, $schema) {
    return TRUE;
  }
  
  /**
   * Saves rows of data previously added via 'addRow'
   * @return boolean
   */
  public final function save() {
    $saved = FALSE;
    if ($this->rows) {
      foreach(array_keys($this->rows) as $table) {
        $csv = sprintf('%s/%s.csv', $this->dir, $table);
        $fp = fopen($csv, 'w');
        print_msg(sprintf('Saving %d rows to CSV file %s', count($this->rows[$table]), basename($csv)), isset($this->options['verbose']), __FILE__, __LINE__);
        $schema = $this->getSchema($table);
        foreach(array_keys($schema) as $i => $col) if ($schema[$col]['type'] != 'index') fwrite($fp, sprintf('%s%s', $i > 0 ? ',' : '', $col));
        fwrite($fp, "\n");
        foreach($this->rows[$table] as $row) {
          foreach(array_keys($schema) as $i => $col) if ($schema[$col]['type'] != 'index') fwrite($fp, sprintf('%s%s', $i > 0 ? ',' : '', isset($row[$col]) ? (strpos($row[$col], ',') ? '"' . str_replace('"', '\"', $row[$col]) . '"' : $row[$col]) : ''));
          fwrite($fp, "\n");
        }
        fclose($fp);
        if (isset($this->options['db'])) {
          if ($this->importCsv($table, $csv, $schema)) {
            print_msg(sprintf('Successfully imported CSV to table %s in %s db', $table, $this->options['db']), isset($this->options['verbose']), __FILE__, __LINE__);
            if (!isset($this->options['db_and_csv']) || !$this->options['db_and_csv']) {
              exec(sprintf('rm -f %s', $csv));
              print_msg(sprintf('Deleted CSV file %s', $csv), isset($this->options['verbose']), __FILE__, __LINE__);
            }
            $saved = TRUE;
          }
          else {
            print_msg(sprintf('Failed to import CSV to table %s in %s db', $table, $this->options['db']), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
            $saved = FALSE;
          }
        }
        else $saved = TRUE;
      }
    }
    return $saved;
  }
  
  /**
   * saves an artifact if an archiver has been set. returns TRUE on success, 
   * FALSE is an archiver was not set and NULL on failure
   * @param string $file path to the artifact to save
   * @param string $col the name of the column in $table where the URL for this
   * artifact should be written
   * @return boolean
   */
  public final function saveArtifact($file, $col) {
    $saved = file_exists($file) ? ($this->archiver ? NULL : FALSE) : NULL;
    if (file_exists($file) && $this->archiver && ($url = $this->archiver->save($file))) {
      $this->artifacts[$col] = $url;
      $saved = TRUE;
    }
    return $saved;
  }
  
  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    $valid = FALSE;
    $dir = isset($this->options['output']) ? $this->options['output'] : trim(shell_exec('pwd'));
    if (!is_dir($dir)) print_msg(sprintf('%s is not a valid output directory', $dir), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    else if (!is_writable($dir)) print_msg(sprintf('%s is not writable', $dir), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    else {
      $this->dir = $dir;
      $valid = TRUE;
    }
    return $valid;
  }
  
  /**
   * validates dependencies for the chosen benchmark db. returns TRUE if 
   * present, FALSE otherwise
   * @return boolean
   */
  private final function validateDependencies() {
    $dependencies = array();
    if (isset($this->options['db'])) {
      switch($this->options['db']) {
        case 'bigquery':
          $dependencies['bq'] = 'Google Cloud SDK';
          break;
        case 'callback':
          $dependencies['curl'] = 'curl';
          break;
        case 'mysql':
          $dependencies['mysql'] = 'mysql';
          break;
        case 'postgresql':
          $dependencies['psql'] = 'postgresql';
          break;
        default:
          $err = '--db ' . $options['db'] . ' is not valid';
          break;
      }
    }
    if ($this->archiver) $dependencies['curl'] = 'curl';
    
    if ($dependencies = validate_dependencies($dependencies)) {
      foreach($dependencies as $dependency) print_msg(sprintf('Missing dependence %s', $dependency), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    return count($dependencies) > 0 ? FALSE : TRUE;
  }
  
}
?>
