<?php

if (!function_exists('mysqli_connect')) throw new \Exception('Missing MYSQLi extension');

/* Usage:
# apt-get install php5-mysqlnd

include "database.php";
Database::config(['user'=>'mysqluser','password'=>'mysqlpassword','db'=>'mysqldatabase','encoding'=>'utf8']);

class User extends DB_Table{
  protected $__table_name = 'users';
}

class UserRow extends DB_Row{
  public function manager(){
    return User::first(['id'=>$this->manager_id]);
  }
}

*/


class Database{
  private static $singleton = false;
  private static $conn = false;


  public static function get_instance(){
    if(self::$singleton === false) return new Database();
    return self::$singleton;
  }


  function __construct(){
    self::$singleton = $this;
  }


  public static function config($config){
    self::$conn = mysqli_connect(isset($config['host'])?$config['host']:'127.0.0.1', $config['user'], $config['password'], $config['db']);
    if (!self::$conn) throw new \Exception("Database connection failed: " . mysqli_connect_error());
    if (isset($config['encoding'])) $this->myq("SET NAMES '".$config['encoding']."'");

    //Load new models
    foreach(get_declared_classes() as $class) if(is_subclass_of($class, 'DB_Table')){
       new $class(self::$conn);
    }
  }


  public static function disconnect(){
    mysqli_close(self::$conn);
  }


  public static function raw_query_cnt($sql){
    $result = self::$conn->query($sql);
    return $result->num_rows;
  }


  public static function raw_query($sql, $conn = false){
    if (!$conn) $conn = self::$conn;
    $result = $conn->query($sql);
    if ($result->num_rows==0) return false;
    $ret = [];
    while($row = $result->fetch_assoc()) $ret[] = $row;
    return $ret;
  }


  public static function start_transaction($conn){
    $conn->autocommit(FALSE);
  }
  public static function rollback($conn){
    $conn->rollback();
  }
  public static function commit($conn){
    $conn->commit();
  }


  public static function prepared_query($sql, $params, $conn = false){
    if (!$conn) $conn = self::$conn;
    $str = '';
    $result = false;
    if (!($stmt = $conn->prepare($sql))) throw new \Exception("Prepare query failed: (" . $conn->errno . ") " . $conn->error);

    $str = ''; $arr = [];
    foreach ($params as $key=>$item){
      $str .= (is_numeric($item)) ? 'i' : 's';
      $arr[] = &$params[$key];
    }
    array_unshift($arr, $str);
    if ($str!='') if (!call_user_func_array([$stmt, 'bind_param'], $arr)) throw new \Exception("Bind parameter failed: (" . $conn->errno . ") " . $conn->error);
#    if ($str!='') if (!$stmt->bind_param($str, $arr)) throw new \Exception("Bind parameter failed: (" . $conn->errno . ") " . $conn->error);
    if (!$stmt->execute()) throw new \Exception("Execute query failed: (" . $conn->errno . ") " . $conn->error);
    $result = $stmt->get_result();
    $stmt->close();

    return $result;
  }


  public static function prepare_where($cols, &$ks, &$vs){
    $ks = "";
    $vs = [];

    foreach ($cols as $k => $v){
      if ($ks!="") $ks.=" AND ";
      if (is_numeric($k)) $ks .= $v;
       elseif ($v === null) $ks .= "`$k` IS NULL";
       else{
        if (substr($k,0,1)=='`') $ks .= "$k=?";
         else $ks .= "`$k`=?";
        $vs[] = $v;
      }
    }
  }

}

Database::get_instance();



class DB_Table{
  private static $singletons = [];
  protected $__table_name = '';
  private $__row_object;
  private $__conn;

  public static function get_instance(){
    if(!isset(self::$singletons[get_called_class()])) throw new \Exception(get_called_class().' was not constructed');
    return self::$singletons[get_called_class()];
  }


  function __construct($conn){
    if ($this->__table_name=='') $this->__table_name = strtolower(get_called_class());
    $this->__row_object = get_called_class().'Row';
    $this->__conn = $conn;
    self::$singletons[get_called_class()] = $this;
  }

  public function make_select($h, $cols = '*', $post = "", $joins = false){
    $join = '';
    if ($joins) foreach ($joins as $name => $conds){
      $join.="JOIN `$name` ON ";
      $i = 0;
      foreach ($conds as $k => $v){
        if ($i++>0) $join.='AND ';
        $join .= "`$name`.`$k`=$v ";
      }
    }

    if (!is_array($h)){
      $s="SELECT $cols FROM `".self::$__table_name."` ".$join;
      if ($h!="") $s.="WHERE $h ";
      return array($s."$post", array());
    }
    $ks = "";
    $vs = array();

    foreach ($h as $k => $v){
      if ($ks!="") $ks.=" AND ";
      if (is_numeric($k)) $ks .= $v;
       elseif ($v === null) $ks .= "`$k` IS NULL";
       else{
        if (substr($k,0,1)=='`') $ks .= "$k=?";
         else $ks .= "`$k`=?";
        $vs[] = $v;
      }
    }
    //First
    $q = "SELECT $cols FROM `".$this->__table_name."` ".$join;
    if ($ks!="") $q.="WHERE $ks ";
    $q.="$post";
    return array($q, $vs);
  }


  public static function first(array $h = array(), $cols = "*", $post = ""){
    $t = self::get_instance();
    return $t->__first($h,$cols,$post);
  }
  private function __first(array $h = array(), $cols = "*", $post = ""){
    $t = self::get_instance();
    list($sql, $params) = $t->make_select($h, $cols, "LIMIT 1");
    $result = Database::prepared_query($sql, $params, $this->__conn);
    if (!($row = $result->fetch_assoc())) return false;

    $rc = $this->__row_object;
    $no = new $rc($row, $this->__table_name, $this->__conn);
    return $no;
  }


  public static function get($id){
    return self::first(['id'=>$id]);
  }

  public static function where($h="", $cols = "*", $post = "", $joins = false){
    $t = self::get_instance();
    return $t->__where($h,$cols,$post,$joins);
  }
  public function __where($h="", $cols = "*", $post = "", $joins = false){
    list($sql, $params) = $this->make_select($h, $cols, $post, $joins);
    $result = Database::prepared_query($sql, $params, $this->__conn);
    $ret = new DB_Selection($this->__table_name, $this->__row_object, $this->__conn);
    $ret->set_result($result);
    return $ret;
  }


  public static function create($cols){
    $t = self::get_instance();
    return $t->__create($cols);
  }
  public function __create($cols){
    if (!is_array($cols)) return false;

    $vs = array();
    $cs = "";
    $os = "";
    foreach ($cols as $k => $v){
      if ($cs!="") $cs.=", ";
      if ($os!="") $os.=", ";
      if (($v!==null)&&($v!==false)){
        $vs[] = $v;

        $cs .= "`$k`";
        $os .= '?';
      }else{
        $cs .="`$k`";
        $os .='null';
      }
    }

    Database::start_transaction($this->__conn);

    $sql="INSERT INTO `".$this->__table_name."` ($cs) VALUES ($os);";
    $result = Database::prepared_query($sql, $vs, $this->__conn);


    $sql="SELECT LAST_INSERT_ID();";
    $result = Database::prepared_query($sql,[], $this->__conn);
    if (!($row = $result->fetch_array(MYSQLI_NUM))){
      Database::rollback($this->__conn);
      return false;
    }
    Database::commit($this->__conn);

    try {
      return $this->__first(['id'=>$row[0]]);
    } catch (\Exception $e) {
      return true;
    }
  }
}


class DB_Selection implements \Iterator{
  private $__table_name;
  private $__row_object;
  private $__conn;
  private $result;
  private $actual=0;
  private $items=[];
  private $loaded=false;

  public function __construct($table_name, $row_object, $conn){
    $this->__table_name = $table_name;
    $this->__row_object = $row_object;
    $this->__conn = $conn;
  }

  public function set_result($result){
    $this->result = $result;
  }

  public function rewind(){
    $actual=0;
  }

  public function current(){
    return $this->items[$this->actual];
  }

  public function key(){
    return $this->actual;
  }

  public function next(){
    $this->actual+=1;
    if (isset($this->items[$this->actual])){
      return $this->current();
    }
    if ($this->loaded) return false;
    if (!($row = $this->result->fetch_assoc())){
      $this->loaded=true;
      return false;
    }
    $rc = $this->__row_object;
    $no = new $rc($row, $this->__table_name, $this->__conn);
    $this->actual+=1;
    $this->items[$this->actual] = $no;
    return $no;
  }

  public function valid(){
    if (isset($this->items[$this->actual])) return true;
    return (bool) $this->__try_get();
  }

  private function __try_get(){
    if ($this->loaded) return false;
    if (!($row = $this->result->fetch_assoc())) return false;
    $rc = $this->__row_object;
    $no = new $rc($row, $this->__table_name, $this->__conn);
    $this->items[$this->actual] = $no;
    return $no;
  }
}



class DB_Row{
  private $__row;
  private $__table_name;
  private $__conn;

  function __construct($row, $table_name, $conn){
    $this->__row = $row;
    $this->__table_name = $table_name;
    $this->__conn = $conn;
  }


  public function &__get($name){
    //Call method
    if (array_key_exists($name, $this->__row)) return $this->__row[$name];
    throw new \Exception('Unknown get: '.$name.' Debug: '.print_r($this->__row,true));
  }


  public function update($h=[]){
    if (empty($h)) return NULL;

    $s = "UPDATE `".$this->__table_name."` ";
    $s.= "SET ";

    $ks = "";
    $vs = array();

    foreach ($h as $k => $v){
      if ($ks!="") $ks.=" AND ";
      if (is_numeric($k)) $ks .= $v;
       elseif ($v === null) $ks .= "`$k` IS NULL";
       else{
        if (substr($k,0,1)=='`') $ks .= "$k=?";
         else $ks .= "`$k`=?";
        $vs[] = $v;
      }
    }
    $s.= $ks;

    //WHERE
    $ks = "";
    foreach ($this->__row as $k => $v){
      if ($ks!="") $ks.=" AND ";
      if (is_numeric($k)) $ks .= $v;
       elseif ($v === null) $ks .= "`$k` IS NULL";
       else{
        if (substr($k,0,1)=='`') $ks .= "$k=?";
         else $ks .= "`$k`=?";
        $vs[] = $v;
      }
    }
    $s.= " WHERE ".$ks;

    Database::prepared_query($s, $vs, $this->__conn);

    foreach ($h as $k => $v) $this->__row[$k]=$v;
    return true;
  }


  public function remove(){
    Database::prepare_where($this->__row, $sel, $vs);
    $sql="DELETE FROM `".$this->__table_name."` WHERE ".$sel;
    return Database::prepared_query($sql, $vs, $this->__conn);
  }
}
