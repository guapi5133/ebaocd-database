<?php
namespace eBaocd\DataBase\Pdo;

use \eBaocd\DataBase\DbException;
use \eBaocd\DataBase\Pdo\DbAbstract;

class Mssql extends DbAbstract
{
	public function __construct($config)
	{
		parent::__construct($config);
		$this->CkConfig();
	}

	//pas_affected_rows,pas_close,pas_Connect,pas_fetch_assoc,pas_fetch_array
	//pas_fetch_row,pas_free_result,pas_Insert_id,pas_num_rows,pas_num_fields
	//pas_Query,pas_select_db,pas_fetch_object
	//abstract public function FetchAll();
	//abstract public function FetchRow();
	//abstract public function FetchAssoc();
	//abstract public function FetchCol();
	//abstract public function fetchOne();

	protected function CkConfig()
	{
		global $APP_G;

		$keys = array("username","password","charset","port","host","database");
		//charset persistent
		foreach ($keys as $k=>$v)
		{
			if(!array_key_exists($v, $this->_config))
			{
				throw new DbException($APP_G['errs'][6000]."[$v]".print_r($this->_config,true));
			}
		}
	}

	/**
	 * 获得当前查询的SQL
	 */
	public function GetQueryString()
	{
		return $this->ToString();
	}

	protected function _Dsn()
	{
		$dsn = sprintf('sqlsrv:server=%s,%s; Database=%s',
				$this->_config['host'],$this->_config['port'],$this->_config['dbname']);

		//"sqlsrv:server=127.0.0.1; Database=Mv_Accounts", "sa", "zxcvbnm"

		return $dsn;
	}

	protected function _Connect()
	{
		if ($this->_Connection) {
			return;
		}
		global $G_X;

		$dsn = $this->_Dsn();

		if (!extension_loaded('pdo')) {
			throw new DbException($G_X['errs'][6001]);
		}
		/*
		 if (!empty($this->_config['charset'])) {
		$initCommand = "SET NAMES '" . $this->_config['charset'] . "'";
		$this->_config['driver_options'][PDO::MYSQL_ATTR_INIT_COMMAND] = $initCommand;
		}

		if (isset($this->_config['persistent']) && ($this->_config['persistent'] == true)) {
		$this->_config['driver_options'][PDO::ATTR_PERSISTENT] = true;
		}*/

		try {
			 
			$this->_Connection = new \PDO(
					$dsn,
					$this->_config['username'],
					$this->_config['password']
					//$this->_config['driver_options'] "sqlsrv:server=127.0.0.1,1433; Database=MV_MicrovoltsAdmin"
			);

			//列名按照原始的方式
			$this->_Connection->setAttribute(PDO::ATTR_CASE, $this->_caseFolding);

			//抛出异常.
			$this->_Connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		} catch (PDOException $e) {
			throw new DbException($e->getMessage(), $e->getCode(), $e);
		}
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
	 * @return array        查询的多行记录.array("allrow"=>array(),"allnum"=>0);
	 */
	public function GetList($table, $where='',$fields='*',$order='id DESC',$page_size=0,$cur_page=0)
	{
		$where  = $this->_WhereExpr($where);
		//print_r ($where);
		$fields = $this->_FieldsExpr($fields);
		//print_r ($fields);
		$result = array("allrow"=>array(),"allnum"=>0);

		if($where){ $where =' WHERE '.$where;}
		if($order){ $sql.=' ORDER BY '.$order;}
		if($cur_page >= 0 && $page_size > 0)
		{
			$sql = <<<SQLEND
WITH OrderedOrders AS
(
	SELECT %s,ROW_NUMBER() OVER (ORDER BY %s) as RowNumber FROM %s %s
)
SELECT * FROM OrderedOrders WHERE RowNumber BETWEEN %d AND %d ORDER BY %s
SQLEND;

			$start = abs(($cur_page - 1)*$page_size) + 1 ;
			$end   = $cur_page *$page_size;
			//echo $start.'--'.$end.'<br>';
			$sql = sprintf($sql,$fields,$order,$table,$where,$start,$end,$order);
				
			$sqlc = "SELECT COUNT(*) AS _num FROM ".$table;
			if($where){ $sqlc .= $where;}
				
			$result['allnum'] = $this->FetchColOne($sqlc);
		}
		elseif($page_size > 0)
		{
			$sql = 'SELECT TOP '.$page_size.' '.$fields.' FROM '.$this->GetTable($table,true);
			if($where){ $sql .= $where;}
			if($order){ $sql .= ' ORDER BY '.$order;}
		}else{
			$sql = 'SELECT '.$fields.' FROM '.$table.$where.$sql;
			$sqlc = "SELECT COUNT(*) AS _num FROM ".$table.$where;
			$result['allnum'] = $this->FetchColOne($sqlc);
		}
		// 	    echo $sql."\n";
		$result['allrow'] = $this->FetchAll($sql);
		return $result;
	}

	/* (non-PHPdoc)
	 * @see Pdo_Abstract::GetTable()
	*/
	public function GetTable($table,$isadd = false)
	{
		$t = $this->QuoteIdentifier($table);
		if($isadd)
		{
			$t .= " WITH(NOLOCK) ";
		}
		 
		return $t;
	}

	/**
	 * 将列名加上[]符号
	 * @param string $key
	 * @return string
	 */
	public function QuoteIdentifier($key)
	{
		$tmp = explode(".", $key);
		foreach ($tmp as $k=>&$v)
		{
			$v = "[$v]";
		}
		$newkey = implode(".", $tmp);
		return $newkey;
	}
}