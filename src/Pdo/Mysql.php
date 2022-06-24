<?php
namespace eBaocd\DataBase\Pdo;

use \eBaocd\DataBase\DbException;
use \eBaocd\DataBase\Pdo\DbAbstract;

class Mysql extends DbAbstract
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
		$dsn = "mysql:host=".$this->_config['host'].";port=".$this->_config['port'].
		";database=".$this->_config['database'];

		//mysql:host=localhost;port=3307;dbname=testdb
        //echo 'dsn:'.$dsn;

		return $dsn;
	}

	protected function _Connect()
	{
		if ($this->_Connection) {
			return;
		}

		$dsn = $this->_Dsn();

		if (!extension_loaded('pdo')) {
			throw new DbException(6001);
		}

		if (!empty($this->_config['charset'])) {
			$initCommand = "SET NAMES '" . $this->_config['charset'] . "'";
			$this->_config['driver_options'][\PDO::MYSQL_ATTR_INIT_COMMAND] = $initCommand;
		}

		if (isset($this->_config['persistent']) && ($this->_config['persistent'] == true)) {
			$this->_config['driver_options'][\PDO::ATTR_PERSISTENT] = true;
		}

		try {

			$this->_Connection = new \PDO(
					$dsn,
					$this->_config['username'],
					$this->_config['password'],
					//$this->_config['driver_options'],
			);

			$this->_Connection->query("SET NAMES '" . $this->_config['charset'] . "'");

			//列名按照原始的方式
			$this->_Connection->setAttribute(\PDO::ATTR_CASE, $this->_caseFolding);

			//抛出异常.
			$this->_Connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $this->_Connection->exec('use `'.$this->_config['database'].'`');

            //echo "[okk]";
		} catch (\PDOException $e) {
            //echo '[error]';
			throw new DbException($e->getMessage(), $e->getCode(), $e);
		}

	}
}
