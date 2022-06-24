<?php

namespace eBaocd\DataBase;

class DbFactory
{
    const RECHECK_FREQUENCY = 300;// 5 minutes
    private static $_instance = NULL;
    private $dbArr = [];

    private function __construct()
    {
    }

    public function __destruct()
    {
        $this->dbArr = NULL;
    }

    public static function Create($dbcfg = 'db')
    {
        return self::getInstance()->getConn($dbcfg);
    }

    public static function getInstance()
    {
        if (NULL == self::$_instance)
        {
            self:: $_instance = new self();
        }

        return self::$_instance;
    }

    public function getConn($dbcfg = 'db')
    {

        $this->ensureConnection2($dbcfg);

        return $this->dbArr[$dbcfg]['dbh'];
    }

    private function ensureConnection()
    {
        if (is_null($this->dbh))
        {
            return $this->makeConnection();
        }

        try
        {
            $status = $this->dbh->getAttribute(PDO::ATTR_SERVER_INFO);
            error_log('MySQL server checked been there');
        }
        catch (PDOException$e)
        {
            if ((int)$e->errorInfo[1] == 2006 && $e->errorInfo[2] == 'MySQLserver has gone away')
            {
                error_log("MySQLserver has gone away, try to reconnection...");

                return $this->makeConnection();
            }

            error_log('Get db server attribute failed: ' . $e->getMessage());
        }

        return $this->dbh;
    }

    private function ensureConnection2($dbcfg = 'db')
    {
        if (!array_key_exists($dbcfg, $this->dbArr) || $this->dbArr[$dbcfg]['dbh'] == NULL)
        {
            return $this->makeConnection($dbcfg);
        }

        try
        {
            $now = time();

            if ($now - $this->dbArr[$dbcfg]['lastCheckTime'] > self::RECHECK_FREQUENCY)
            {
                $this->dbArr[$dbcfg]['lastCheckTime'] = $now;
                $status                               = $this->dbArr[$dbcfg]['dbh']->query("select 1");
                //error_log('MySQL server checked been there');
            }

        }
        catch (\PDOException $e)
        {
            if ((int)$e->errorInfo[1] == 2006 && $e->errorInfo[2] == 'MySQL server has gone away')
            {
                error_log("MySQL server has gone away, try to reconnection...");

                return $this->makeConnection($dbcfg);
            }

            error_log('Get db server attribute failed: ' . $e->getMessage());
        }

        return $this->dbArr[$dbcfg]['dbh'];
    }

    private function makeConnection($dbcfg = 'db')
    {
        //$dbArr[$dbcfg] = ['lastCheckTime' => time(),'dbh'=>NULL] ;
        try
        {
            global $APP_G;


            $this->dbArr[$dbcfg] = [
                'lastCheckTime' => time(),
                'dbh'           => new \eBaocd\DataBase\Pdo\Mysql($APP_G[$dbcfg])
            ];

            return $this->dbArr[$dbcfg]['dbh'];
        }
        catch (\PDOException $e)
        {
            error_log('Connection failed: ' . $e->getMessage());
            exit();
        }

        return NULL;
    }
}
