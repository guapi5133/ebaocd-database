<?php
namespace eBaocd\DataBase\Pdo;

use \eBaocd\DataBase\DbException;

abstract class DbAbstract
{
	private $_Querystring = null;
	private $_Queryparam = array();
	protected $_config = array();
	protected $_fetchMode = \PDO::FETCH_ASSOC;

	protected $_Connection = null;
	protected $_caseFolding = \PDO::CASE_NATURAL;

	protected $_autoQuoteIdentifiers = true;

	public function __construct($config)
	{
		$this->_config = $config;
	}

	public function GetConnection()
	{
		$this->_Connect();
		return $this->_Connection;
	}

	public function FmtParams(&$bind)
	{
		if(!is_array($bind))$bind = array($bind);

		foreach ($bind as $name => $value) {
			if (!is_int($name) && !preg_match('/^:/', $name)) {
				$newName = ":$name";
				unset($bind[$name]);
				$bind[$newName] = $value;
			}
		}
	}

	/**
	 * @param string $table
	 * @param boolean $isadd 是否加 WITH(NOLOCK),如INSERT不用加
	 * @return string 获得表名,SQLSERVER所有表要加 WITH(NOLOCK)
	 */
	public function GetTable($table,$isadd = false)
	{
		if($isadd)
		{
			return $this->QuoteIdentifier($table);
		}else{
			return $table;
		}

	}

	public function Query($sql, $bind = array())
	{
		$this->_Connect();

		$this->FmtParams($bind);

		$this->_Querystring = $sql;
		$this->_Queryparam = $bind;

		// prepare and execute the statement with profiling
		$stmt = $this->GetConnection()->prepare($sql);

		$stmt->execute($bind);
		// return the results embedded in the prepared statement object
		$stmt->SetFetchMode(\PDO::FETCH_ASSOC);

		return $stmt;

	}

	public function ToString()
	{
		$msg = "sql==>" .$this->_Querystring . "param==>";
		$msg .= print_r($this->_Queryparam,true);
		return $msg;
	}


	/**
	 * 自动组合SQL语句:
	 * Insert into tba (`cola`,`colb`) values (?,?)==>
	 * array("cola"=>value1,"colb"=>value2)
	 * @param unknown_type $table
	 * @param array $bind array("colname"=>value1,"colname"=>value2)
	 * @param boolean $brtnId 是否返回自增长ID
	 * @return 成功返回自增加ID，否则返回0
	 */
	public function Insert($table, array $bind,$brtnId = TRUE)
	{
		$cols = array();
		$vals = array();
		foreach ($bind as $col => $val) {
			$cols[] = $this->QuoteIdentifier($col);
			if (is_null($val)) {
				$vals[] = "NULL";
				unset($bind[$col]);
			} else {
				$vals[] = '?';
			}
		}

		// build the statement
		$sql = "Insert INTO "
				. $this->GetTable($table,false)
				. ' (' . implode(', ', $cols) . ') '
						. 'VALUES (' . implode(', ', $vals) . ')';

		// execute the statement and return the number of affected rows
		$stmt = $this->Query($sql, array_values($bind));
		$result = $stmt->rowCount();
		if($result > 0 && $brtnId)
		{
			$result = $this->GetConnection()->LastInsertId();
		}
		$stmt->closeCursor();

		return $result;
	}

	/**
	 * 更新表数据
	 * Update $table set `cola`
	 * @param string $table
	 * @param array $bind array("cola"=>"value1","colb"=>"value2")
	 * @param string $where "and cola='1'" 或者 array("cola"=>"1")
	 * @return int 影响的行数
	 */
	public function Update($table, array $bind, $where = '')
	{
		$set = array();
		$i = 0;
		foreach ($bind as $col => $val) {
            $rest  = explode("_", $col);
            $rest1 = end($rest);

			if(in_array($rest1, array("+=","-=","*=","/=")))
			{
                $rest1 = array_pop($rest);
				$val = $this->Quote($val);
				$col2 = $this->QuoteIdentifier(implode('_', $rest), true);
				switch ($rest1)
				{
					case '-=':
						$set[] = $col2.'='.$col2.' - ?';
						break;
					case '*=':
						$set[] = $col2.'='.$col2.' * ?';
						break;
					case '/=':
						$set[] = $col2.'='.$col2.' / ?';
						break;
					case '+=':
					default:
						$set[] = $col2.'='.$col2.' + ?';
						break;
				}
				//$set[] = $this->QuoteIdentifier($rest[0], true) . $rest1 . $val;
			}else{
				if (is_null($val)) {
					$val = "NULL";
					unset($bind[$col]);
				}else if($val == "" && $val != '0') {
					$val = "''";
					unset($bind[$col]);
				} else {
					$val = '?';
				}
				$set[] = $this->QuoteIdentifier($col, true) . " = " . $val;
			}
		}

		$where = $this->_WhereExpr($where);

		$sql = "UPDATE "
				. $this->GetTable($table,false)
				. ' SET ' . implode(', ', $set)
				. (($where) ? " WHERE $where" : '');

		$stmt = $this->Query($sql, array_values($bind));
		//echo $sql;
		//print_r($bind);
		$result = $stmt->rowCount();
		$stmt->closeCursor();

		return $result;
	}

	/**
	 * Deletes table rows based on a WHERE clause.
	 *
	 * @param  mixed        $table The table to Update.
	 * @param  mixed        $where 形如 "and cola='1'" 或者 array("cola"=>"1")
	 * @return int          影响的行数.-1表示出错
	 */
	public function Delete($table, $where = '')
	{
		$where = $this->_WhereExpr($where);

		if($where == '')
		{
			return -1;
		}

		$sql = "Delete FROM "
				. $this->GetTable($table,false)
				. (($where) ? " WHERE $where" : '');

		$stmt = $this->Query($sql);
		$result = $stmt->rowCount();
		$stmt->closeCursor();

		return $result;
	}
	/**
	 * select table rows based on a WHERE clause.
	 *
	 * @param  mixed        $table The table to select.
	 * @param  mixed        $where 形如 "and cola='1'" 或者 array("cola"=>"1")
	 * @param  mixed        $fields 形如 "names,ids,psd" 或者 array('names','ids','psd')
	 * @params mixed        $order  形如 ' id DESC,uid ASC' 作用排序
	 * @params int			$page_size 每页多少条 ，
	 * 						如与$cur_page同时为0则取全表，
	 * 						若$pagesize>0,$cur_page=0则返回前$pagesize条
	 * 						若均大于0，则返回总条数，及当前页$pagesize条
	 * @params int			$cur_page 当前第几页
	 * @return array        查询的多行记录;
	 */
	public function GetList($table, $where='',$fields='*',$order='',$page_size=0,$cur_page=0,$isAddfh=true)
	{
		$where  = $this->_WhereExpr($where);
		$fields = $this->_FieldsExpr($fields);
		//$result = array("allrow"=>array(),"allnum"=>0);

		$sql = 'SELECT '.$fields.' FROM '.$this->GetTable($table,$isAddfh);
		if($where){ $sql.=' WHERE '.$where;}
		if($order){ $sql.=' ORDER BY '.$order;}

		// 		echo $cur_page.$page_size;
		if($cur_page >= 0 && $page_size > 0)
		{
            $start = ($cur_page - 1) * $page_size;
            $sql   .= " limit $start,$page_size";
		}
		elseif($page_size > 0)
		{
            $sql .= " limit 0, $page_size";
		}

        return  $this->FetchAll($sql);

		// echo $sql."\n";
		//$result['allrow'] = $this->FetchAll($sql);
        //return $result;
	}

	public function IsExists($table,$where)
	{
		$sql = "SELECT COUNT(*) AS num FROM ".$this->GetTable($table,true);

		if (is_array($where))
		{
            $where = $this->_WhereExpr($where);
		}

		if($where){ $sql.=' WHERE '.$where;}

		return $this->FetchColOne($sql);
	}

	public function _FieldsExpr($fields='*'){
		if (!is_array($fields)) {
			return $fields;
		}
		return implode(',', $fields);

	}
	//编排where  $allowfh 中的字段使用事例 ： $where['StatusID_!='] = '-1';  》 [StatusID] != '-1'
	private function _FmtWhere($where)
	{
		if (empty($where)) {
			return $where;
		}
		if (!is_array($where)) {
			return $where;
		}

		$allowfh = array("LIKE","RLIKE","LLIKE","NOTLIKE",">",">=","<","<>","<=","!=","IN","NOTIN",'IS','UNKEY');
		foreach ($where as $cond => &$term) {
			//$rest = strlen($cond)> 5 ? strtoupper(substr($cond, -5)) : '';
			$rest = explode("_", $cond);
			$fldop = '-1';
			if(count($rest) > 1)
			{
				$fldop = array_pop($rest);
				$fldop = strtoupper($fldop);
				$rest = implode("_", $rest);
			}
			if(array_key_exists("LIKE", $where) && in_array($cond,$where['LIKE']))
			{
				$term = $this->QuoteIdentifier($cond)." LIKE '%".$this->Quote($term)."%'";
			}else if($fldop != '-1' && in_array($fldop, $allowfh)){// $rest == "_LIKE"){
				switch ($fldop)
				{
					case "LIKE":
						$term = addslashes($term);
						$term = strpos($term,'%')!==false ? str_replace("%","\%",$term) : $term;
						$term = $this->QuoteIdentifier($rest)." LIKE '%".$term."%'";
						break;
					case "RLIKE":
						$term = addslashes($term);
						$term = strpos($term,'%')!==false ? str_replace("%","\%",$term) : $term;
						$term = $this->QuoteIdentifier($rest)." LIKE '".$term."%'";
					break;
					case "LLIKE":
						$term = addslashes($term);
						$term = strpos($term,'%')!==false ? str_replace("%","\%",$term) : $term;
						$term = $this->QuoteIdentifier($rest)." LIKE '%".$term."'";
					break;
					case "NOTLIKE":
						$term = addslashes($term);
						$term = $this->QuoteIdentifier($rest)." NOT LIKE '$term'";
					break;
					case ">":
					case ">=":
					case "<":
					case "<=":
					case "<>":
					case "!=":
						$term = $this->QuoteIdentifier($rest)." ".$fldop." '".addslashes($term)."'";
						break;
					case "IS":
						$term = $this->QuoteIdentifier($rest)." ".$fldop." ".addslashes($term)."";
						break;
					case "IN":
						if(!is_array($term))
						{
							$term = explode(",", $term);
						}
						$this->fmt_arr_addslashes($term);
						$term = $this->QuoteIdentifier($rest)." IN ('".implode("','", $term)."')";
						break;
					case "NOTIN":
						if(!is_array($term))
						{
							$term = explode(",", $term);
						}
						$this->fmt_arr_addslashes($term);
						$term = $this->QuoteIdentifier($rest)." NOT IN ('".implode("','", $term)."')";
						break;
					case "UNKEY":
						$term = $this->QuoteIdentifier($rest)."=".$this->QuoteIdentifier($term);
						break;
				}
			}else{
				$term = $this->QuoteIdentifier($cond)."=".$this->Quote($term);
			}
			$term = '(' . $term . ')';
		}
		return $where;
	}

	public function fmt_arr_addslashes(&$arr) {
		if (is_array ( $arr )) {
			foreach ( $arr as $k => &$v ) {
				$v = addslashes ( $v );
			}
		} else {
			$arr = addslashes ( $arr );
		}
	}

	/**
	 * Convert an array, string, or Zend_Db_Expr object
	 * into a string to put in a WHERE clause.
	 *
	 * @param mixed $where 形如array("pid"=>1,"cnname_like"=>"中")
	 *              KEY包含了_LIKE则其值like '%val%'
	 *              "LIKE",">",">=","<","<=","!=","IN","NOTIN"
	 * @return string
	 */
	public function _WhereExpr($where)
	{
		if(!is_array($where))return $where;
		if(count($where) == 0)return '';
		$sql = '';
		if(array_key_exists("OR", $where))
		{
			$where2 = $this->_FmtWhere($where['OR']);
			unset($where['OR']);
			if(!empty($where2))
			{
				$sql .= ' AND ('.implode(' OR ', $where2).') ';
			}
		}
		$where3 = $this->_FmtWhere($where);
		if(!empty($where3))
		{
			$sql .= ' AND ('.implode(' AND ', $where3).') ';
		}

		$sql = trim($sql,' AND');
		return $sql;
	}

	/**
	 * 将字段名格式化为参数化
	 * @param string $field 包含了_LIKE则其值like '%?%'
	 *              "LIKE",">",">=","<","<=","!=","IN","NOTIN"
	 * @return string
	 */
	public function FmtFieldWithParam($field,$v,&$isunset)
	{
		$arr = explode("_",$field);
		$term = "";
		if(count($arr) < 2)
		{
			return $this->QuoteIdentifier($field)."=?";
		}
		$fldop = array_pop($arr);
		$fldop = strtoupper($fldop);
		$rest = implode("_", $arr);
		switch ($fldop)
		{
			case "LIKE":
				$term = $this->QuoteIdentifier($rest)." LIKE ?";
				break;
			case ">":
			case ">=":
			case "<":
			case "<=":
			case "<>":
			case "!=":
				$term = $this->QuoteIdentifier($rest)." ".$fldop." ?";
				break;
			case "IN":
				if(!is_array($v))
				{
					$v = explode(",", $v);
				}
				$this->fmt_arr_addslashes($v);
				$term = $this->QuoteIdentifier($rest)." IN ('".implode("','", $v)."')";
				$isunset = true;
				break;
			case "NOTIN":
				if(!is_array($v))
				{
					$v = explode(",", $v);
				}
				$this->fmt_arr_addslashes($v);
				$term = $this->QuoteIdentifier($rest)." NOT IN ('".implode("','", $v)."')";
				$isunset = true;
				break;
			default:
				$term = $this->QuoteIdentifier($field)."=?";
				break;
		}

		return $term;
	}

	public function WhereExpr($where)
	{
		return $this->_WhereExpr($where);
	}

	/**
	 * Returns the configuration variables in this adapter.
	 *
	 * @return array
	 */
	public function GetConfig()
	{
		return $this->_config;
	}

	public function GetFetchMode()
	{
		return $this->_fetchMode;
	}

	public function SetFetchMode($mode)
	{
		global $G_X;

		if (!extension_loaded('pdo')) {
			throw new DbException($G_X['errs'][6001]);
		}
		switch ($mode) {
			case \PDO::FETCH_LAZY:
			case \PDO::FETCH_ASSOC:
			case \PDO::FETCH_NUM:
			case \PDO::FETCH_BOTH:
			case \PDO::FETCH_NAMED:
			case \PDO::FETCH_OBJ:
				$this->_fetchMode = $mode;
				break;
			default:
				$this->_fetchMode = \PDO::FETCH_ASSOC;
				break;
		}
	}

	protected function _Quote($value)
	{
		if (is_int($value)) {
			return $value;
		} elseif (is_float($value)) {
			return sprintf('%F', $value);
		}elseif(is_null($value))
		{
			return "NULL";
		}
		return "'" . addcslashes($value, "\000\n\r\\'\"\032") . "'";
	}

	/**
	 * 将字段值格式化
	 * @param mixed $value
	 * @return mixed
	 */
	public function Quote($value)
	{
		return $this->_Quote($value);
	}

	/**
	 * 将列名加上`符号
	 * @param string $key
	 * @return string
	 */
	public function QuoteIdentifier($key)
	{
		$tmp = explode(".", $key);
		foreach ($tmp as $k=>&$v)
		{
			$v = "`$v`";
		}
		$newkey = implode(".", $tmp);
		return $newkey;
	}

	/**
	 * 是否已连接
	 * @return boolean
	 */
	public function IsConnected()
	{
		return ((bool) ($this->_Connection instanceof \PDO));
	}

	/**
	 * 关闭连接
	 *
	 * @return void
	 */
	public function CloseConnection()
	{
		$this->_Connection = null;
	}

	/**
	 * 返回最近一次的插入ID
	 * 但有可能有误，如进行这样操作时：
	 * "Insert into city(cityid,city,countryid) values(NULL,'北京',2),(NULL,'南京',2)"
	 * 需测试
	 */
	public function LastInsertId()
	{
		$this->_Connect();
		return $this->_Connection->LastInsertId();
	}

	/**
	 * Begin a transaction.
	 */
	protected function _BeginTransaction()
	{
		$this->_Connect();
		$this->_Connection->BeginTransaction();
	}

	/**
	 * Commit a transaction.
	 */
	protected function _Commit()
	{
		$this->_Connect();
		$this->_Connection->Commit();
	}

	/**
	 * Roll-back a transaction.
	 */
	protected function _RollBack() {
		$this->_Connect();
		$this->_Connection->RollBack();
	}

	/**
	 * Leave autoCommit mode and begin a transaction.
	 *
	 * @return Pdo_Abstract
	 */
	public function BeginTransaction()
	{
		$this->_Connect();
		$this->_BeginTransaction();
		return $this;
	}

	/**
	 * Commit a transaction and return to autoCommit mode.
	 *
	 * @return Pdo_Abstract
	 */
	public function Commit()
	{
		$this->_Connect();
		$this->_Commit();
		return $this;
	}

	/**
	 * Roll back a transaction and return to autoCommit mode.
	 *
	 * @return Pdo_Abstract
	 */
	public function RollBack()
	{
		$this->_Connect();
		$this->_RollBack();
		return $this;
	}

	abstract protected function _Connect();

	public function FetchAll($sql, $bind = array(), $fetchMode = null)
	{
		if ($fetchMode === null) {
			$fetchMode = \PDO::FETCH_ASSOC;//$this->_fetchMode;
		}
		$fetchMode = \PDO::FETCH_ASSOC;
		$stmt = $this->Query($sql, $bind);
		$result = $stmt->FetchAll($fetchMode);

		$stmt->closeCursor();
		return $result;
	}

	/**
	 * Fetches the first row of the SQL result.
	 * Uses the current fetchMode for the adapter.
	 *
	 * @param string|Zend_Db_Select $sql An SQL SELECT statement.
	 * @param mixed $bind Data to bind into SELECT placeholders.
	 * @param mixed                 $fetchMode Override current fetch mode.
	 * @return array
	 */
	public function FetchRow($sql, $bind = array(), $fetchMode = null)
	{
		if ($fetchMode === null) {
			$fetchMode = $this->_fetchMode;
		}
		$stmt = $this->Query($sql, $bind);
		$result = $stmt->fetch($fetchMode);
		$stmt->closeCursor();

		return $result;
	}

	/**
	 * 返回第一列为KEY，值为ROW的记录集
	 *
	 * The first column is the key, the entire row array is the
	 * value.  You should construct the Query to be sure that
	 * the first column contains unique values, or else
	 	* rows with duplicate values in the first column will
	 * overwrite previous data.
	 *
	 * @param string $sql An SQL SELECT statement.
	 * @param mixed $bind Data to bind into SELECT placeholders.
	 * @return array
	 */
	public function FetchAssoc($sql, $bind = array(),$iskeyvalue = false)
	{
		$stmt = $this->Query($sql, $bind);
		$data = array();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			if($iskeyvalue)
			{
				$tmp = array_values(array_slice($row, 0, 2));
				$data[$tmp[0]] = $tmp[1];
			}else{
				$tmp = array_values(array_slice($row, 0, 1));
				$data[$tmp[0]] = $row;
			}
		}
		return $data;
	}

	/**
	 * 返回第一列值
	 * Fetches the first column of all SQL result rows as an array.
	 *
	 * The first column in each row is used as the array key.
	 *
	 * @param string $sql An SQL SELECT statement.
	 * @param mixed $bind Data to bind into SELECT placeholders.
	 * @return array
	 */
	public function FetchCol($sql, $bind = array())
	{
		$stmt = $this->Query($sql, $bind);
		$result = $stmt->FetchAll(\PDO::FETCH_COLUMN, 0);
		return $result;
	}

	/**返回第一行第一列值
	 * Fetches the first column of the first row of the SQL result.
	*
	* @param string|Zend_Db_Select $sql An SQL SELECT statement.
	* @param mixed $bind Data to bind into SELECT placeholders.
	* @return string
	*/
	public function FetchColOne($sql, $bind = array())
	{
		$stmt = $this->Query($sql, $bind);
		$result = $stmt->FetchColumn(0);
		return $result;
	}
}

?>
