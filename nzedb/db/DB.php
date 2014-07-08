<?php
namespace nzedb\db;

//use nzedb\controllers\ColorCLI;

/**
 * Class for handling connection to database (MySQL or PostgreSQL) using PDO.
 *
 * The class extends PDO, thereby exposing all of PDO's functionality directly
 * without the need to wrap each and every method here.
 *
 * Exceptions are caught and displayed to the user.
 * Properties are explicitly created, so IDEs can offer autocompletion for them.
 */
class DB extends \PDO
{
	/**
	 * @var object Instance of ConsoleTools class.
	 */
	public $ct;

	/**
	 * @var object    Instance variable for logging object. Currently only ColorCLI supported,
	 * but expanding for full logging with agnostic API planned.
	 */
	public $log;

	/**
	 * @var bool	Whether memcache is enabled.
	 */
	public $memcached;

	/**
	 * @var bool Is this a Command Line Interface instance.
	 */
	protected $_cli;

	/**
	 * @var bool
	 */
	protected $_debug;

	/**
	 * @var object Instance of PDO class.
	 */
	protected static $_pdo = null;

	/**
	 * @var object Class instance debugging.
	 */
	private $debugging;

	/**
	 * @var string Lower-cased name of DBMS in use.
	 */
	private $dbSystem;

	/**
	 * @var string Version of the Db server.
	 */
	private $dbVersion;

	/**
	 * @var string	Stored copy of the dsn used to connect.
	 */
	private $dsn;

	/**
	 * @var array    Options passed into the constructor or defaulted.
	 */
	private $opts;

	/**
	 * Constructor. Sets up all necessary properties. Instantiates a PDO object
	 * if needed, otherwise returns the current one.
	 */
	public function __construct(array $options = array())
	{
		$defaults = array(
			'checkVersion'	=> false,
			'createDb'		=> false, // create dbname if it does not exist?
			'ct'			=> new \ConsoleTools(),
			'dbhost'		=> defined('DB_HOST') ? DB_HOST : '',
			'dbname' 		=> defined('DB_NAME') ? DB_NAME : '',
			'dbpass' 		=> defined('DB_PASSWORD') ? DB_PASSWORD : '',
			'dbport'		=> defined('DB_PORT') ? DB_PORT : '',
			'dbsock'		=> defined('DB_SOCKET') ? DB_SOCKET : '',
			'dbtype'		=> defined('DB_SYSTEM') ? DB_SYSTEM : '',
			'dbuser' 		=> defined('DB_USER') ? DB_USER : '',
			'log'			=> new \ColorCLI(),
			'persist'		=> false,
		);
		$this->opts = $options + $defaults;

		$this->_debug = (nZEDb_DEBUG || nZEDb_LOGGING);
		if ($this->_debug) {
			$this->debugging = new \Debugging("DB");
		}

		$this->_cli = \nzedb\utility\Utility::isCLI();

		if (!empty($this->opts['dbtype'])) {
			$this->DbSystem = strtolower($this->opts['dbtype']);
		}

		if (!(self::$_pdo instanceof \PDO)) {
			$this->initialiseDatabase();
		}

		if (defined("MEMCACHE_ENABLED")) {
			$this->memcached = MEMCACHE_ENABLED;
		} else {
			$this->memcached = false;
		}
		$this->ct = $this->opts['ct'];
		$this->log = $this->opts['log'];

		if ($this->opts['checkVersion']) {
			$this->fetchDbVersion();
		}

		return self::$_pdo;
	}

	public function checkDbExists ($name = null)
	{
		if (empty($name)) {
			$name = $this->opts['dbname'];
		}

		$found  = false;
		$tables = self::getTableList();
		foreach ($tables as $table) {
			if ($table['Database'] == $name) {
				//var_dump($tables);
				$found = true;
				break;
			}
		}
		return $found;
	}

	/**
	 * Looks up info for index on table.
	 *
	 * @param $table	Table to look at.
	 * @param $index	Index to check.
	 *
	 * @return bool|array	False on failure, associative array of SHOW data.
	 */
	public function checkIndex($table, $index)
	{
		$result = self::$_pdo->query(sprintf("SHOW INDEX FROM %s WHERE key_name = '%s'",
										trim($table),
										trim($index)));
		if ($result === false) {
			return false;
		}

		return $result->fetch(\PDO::FETCH_ASSOC);
	}

	public function checkColumnIndex($table, $column)
	{
		$result = self::$_pdo->query(sprintf("SHOW INDEXES IN %s WHERE non_unique = 0 AND column_name = '%s'",
										trim($table),
										trim($column)
								));
		if ($result === false) {
			return false;
		}
//var_dump($result);
		return $result->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function getTableList ()
	{
		$query  = ($this->opts['dbtype'] === 'mysql' ? 'SHOW DATABASES' :
			'SELECT datname AS Database FROM pg_database');
		$result = self::$_pdo->query($query);
		return $result->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * @return bool Whether the Db is definitely on the local machine.
	 */
	public function isLocalDb ()
	{
		if (!empty($this->opts['dbsock']) || $this->opts['dbhost'] == 'localhost') {
			return true;
		}

		preg_match_all('/inet' . '6?' . ' addr: ?([^ ]+)/', `ifconfig`, $ips);

		// Check for dotted quad - if exists compare against local IP number(s)
		if (preg_match('#^\d+\.\d+\.\d+\.\d+$#', $this->opts['dbhost'])) {
			if (in_array($this->opts['dbhost'], $ips[1])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Init PDO instance.
	 */
	private function initialiseDatabase()
	{
		if ($this->DbSystem === 'mysql') {
			if (!empty($this->opts['dbsock'])) {
				$dsn = $this->DbSystem . ':unix_socket=' . $this->opts['dbsock'];
			} else {
				$dsn = $this->DbSystem . ':host=' . $this->opts['dbhost'];
				if (!empty($this->opts['dbport'])) {
					$dsn .= ';port=' . $this->opts['dbport'];
				}
			}
		} else {
			$dsn = $this->DbSystem . ':host=' . $this->opts['dbhost'] . ';dbname=' . $this->opts['dbname'];
		}
		$dsn .= ';charset=utf8';

		$options = array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 180,
						 \PDO::ATTR_PERSISTENT => $this->opts['persist']);
		if ($this->DbSystem === 'mysql') {
			$options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8";
			$options[\PDO::MYSQL_ATTR_LOCAL_INFILE] = true;
		}

		$this->dsn = $dsn;
		// removed try/catch to let the instantiating code handle the problem (Install for
		// instance can output a message that connecting failed.
		self::$_pdo = new \PDO($dsn, $this->opts['dbuser'], $this->opts['dbpass'], $options);

		$found = self::checkDbExists();
		if ($this->opts['dbtype'] === 'pgsql' && !$found) {
			throw new \RuntimeException('Could not find your database: ' . $this->opts['dbname'] .
										', please see Install.txt for instructions on how to create a database.', 1);
		}

		if ($this->opts['createDb']) {
			if ($found) {
				try {
					self::$_pdo->query("DROP DATABASE " . $this->opts['dbname']);
				} catch (Exception $e) {
					throw new \RuntimeException("Error trying to drop your old database: '{$this->opts['dbname']}'", 2);
				}
				$found = self::checkDbExists();
			}

			if ($found) {
				var_dump(self::getTableList());
				throw new \RuntimeException("Could not drop your old database: '{$this->opts['dbname']}'", 2);
			} else {
				self::$_pdo->query("CREATE DATABASE `{$this->opts['dbname']}`  DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci");

				if (!self::checkDbExists()) {
					throw new \RuntimeException("Could not create new database: '{$this->opts['dbname']}'", 3);
				}
			}
		}
		self::$_pdo->query("USE {$this->opts['dbname']}");
		//		var_dump('made it here');

		// In case PDO is not set to produce exceptions (PHP's default behaviour).
		if (self::$_pdo === false) {
			$this->echoError(
				 "Unable to create connection to the Database!",
				 'initialiseDatabase',
				 1,
				 true
			);
		}

		// For backwards compatibility, no need for a patch.
		self::$_pdo->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_LOWER);
		self::$_pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
	}

	/**
	 * Echo error, optionally exit.
	 *
	 * @param string	$error		The error message.
	 * @param string	$method		The method where the error occured.
	 * @param int		$severity	The severity of the error.
	 * @param bool		$exit		Exit or not?
	 * @param exception	$e			Previous exception object.
	 */
	protected function echoError ($error, $method, $severity, $exit = false, $e = null)
	{
		if ($this->_debug) {
			$this->debugging->start($method, $error, $severity);

			echo(($this->_cli ? $this->log->error($error) . PHP_EOL :
				'<div class="error">' . $error . '</div>'));
		}

		if ($exit) {
			exit();
		}
	}

	/**
	 * @return string mysql or pgsql.
	 */
	public function DbSystem()
	{
		return $this->DbSystem;
	}

	/**
	 * Returns a string, escaped with single quotes, false on failure. http://www.php.net/manual/en/pdo.quote.php
	 *
	 * @param string $str
	 *
	 * @return string
	 */
	public function escapeString($str)
	{
		if (is_null($str)) {
			return 'NULL';
		}

		return self::$_pdo->quote($str);
	}

	/**
	 * Formats a 'like' string. ex.(LIKE '%chocolate%')
	 *
	 * @param string $str    The string.
	 * @param bool   $left   Add a % to the left.
	 * @param bool   $right  Add a % to the right.
	 *
	 * @return string
	 */
	public function likeString($str, $left=true, $right=true)
	{
		return (
			($this->DbSystem === 'mysql' ? 'LIKE ' : 'ILIKE ') .
			$this->escapeString(
				($left  ? '%' : '') .
				$str .
				($right ? '%' : '')
			)
		);
	}

	/**
	 * Verify if pdo var is instance of PDO class.
	 *
	 * @return bool
	 */
	public function isInitialised()
	{
		return (self::$_pdo instanceof \PDO);
	}

	/**
	 * For inserting a row. Returns last insert ID. queryExec is better if you do not need the id.
	 *
	 * @param string $query
	 *
	 * @return bool
	 */
	public function queryInsert($query)
	{
		if (empty($query)) {
			return false;
		}

		if (nZEDb_QUERY_STRIP_WHITESPACE) {
			$query = \nzedb\utility\Utility::collapseWhiteSpace($query);
		}

		$i = 2;
		$error = '';
		while($i < 11) {
			$result = $this->queryExecHelper($query, true);
			if (is_array($result) && isset($result['deadlock'])) {
				$error = $result['message'];
				if ($result['deadlock'] === true) {
					$this->echoError("A Deadlock or lock wait timeout has occurred, sleeping.(" . ($i-1) . ")", 'queryInsert', 4);
					$this->ct->showsleep($i * ($i/2));
					$i++;
				} else {
					break;
				}
			} elseif ($result === false) {
				$error = 'Unspecified error.';
				break;
			} else {
				return $result;
			}
		}
		if ($this->_debug) {
			$this->echoError($error, 'queryInsert', 4);
			$this->debugging->start("queryInsert", $query, 6);
		}
		return false;
	}

	/**
	 * Used for deleting, updating (and inserting without needing the last insert id).
	 *
	 * @param string $query
	 *
	 * @return bool
	 */
	public function queryExec($query)
	{
		if (empty($query)) {
			return false;
		}

		if (nZEDb_QUERY_STRIP_WHITESPACE) {
			$query = \nzedb\utility\Utility::collapseWhiteSpace($query);
		}

		$i = 2;
		$error = '';
		while($i < 11) {
			$result = $this->queryExecHelper($query);
			if (is_array($result) && isset($result['deadlock'])) {
				$error = $result['message'];
				if ($result['deadlock'] === true) {
					$this->echoError("A Deadlock or lock wait timeout has occurred, sleeping.(" . ($i-1) . ")", 'queryExec', 4);
					$this->ct->showsleep($i * ($i/2));
					$i++;
				} else {
					break;
				}
			} elseif ($result === false) {
				$error = 'Unspecified error.';
				break;
			} else {
				return $result;
			}
		}
		if ($this->_debug) {
			$this->echoError($error, 'queryExec', 4);
			$this->debugging->start("queryExec", $query, 6);
		}
		return false;
	}

	/**
	 * Helper method for queryInsert and queryExec, checks for deadlocks.
	 *
	 * @param string $query
	 * @param bool   $insert
	 *
	 * @return array
	 */
	protected function queryExecHelper($query, $insert = false)
	{
		try {
			if ($insert === false ) {
				$run = self::$_pdo->prepare($query);
				$run->execute();
				return $run;
			} else {
				if ($this->DbSystem === 'mysql') {
					$ins = self::$_pdo->prepare($query);
					$ins->execute();
					return self::$_pdo->lastInsertId();
				} else {
					$p = self::$_pdo->prepare($query . ' RETURNING id');
					$p->execute();
					$r = $p->fetch(\PDO::FETCH_ASSOC);
					return $r['id'];
				}
			}

		} catch (\PDOException $e) {
			// Deadlock or lock wait timeout, try 10 times.
			if (
				$e->errorInfo[1] == 1213 ||
				$e->errorInfo[0] == 40001 ||
				$e->errorInfo[1] == 1205 ||
				$e->getMessage() == 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
			) {
				return array('deadlock' => true, 'message' => $e->getMessage());
			}

			// Check if we lost connection to MySQL.
			else if ($this->_checkGoneAway($e->getMessage()) !== false) {

				// Reconnect to MySQL.
				if ($this->_reconnect() === true) {

					// If we reconnected, retry the query.
					return $this->queryExecHelper($query, $insert);

				}
			}

			return array ('deadlock' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * Direct query. Return the affected row count. http://www.php.net/manual/en/pdo.exec.php
	 *
	 * @param string $query
	 * @param bool   $silent Whether to skip echoing errors to the console.
	 *
	 * @return bool|int
	 */
	public function exec($query, $silent = false)
	{
		if (empty($query)) {
			return false;
		}

		if (nZEDb_QUERY_STRIP_WHITESPACE) {
			$query = \nzedb\utility\Utility::collapseWhiteSpace($query);
		}

		try {
			return self::$_pdo->exec($query);

		} catch (\PDOException $e) {

			// Check if we lost connection to MySQL.
			if ($this->_checkGoneAway($e->getMessage()) !== false) {

				// Reconnect to MySQL.
				if ($this->_reconnect() === true) {

					// If we reconnected, retry the query.
					return $this->exec($query, $silent);

				} else {
					// If we are not reconnected, return false.
					return false;
				}

			} else if (!$silent) {
				$this->echoError($e->getMessage(), 'Exec', 4, false, $e);

				if ($this->_debug) {
					$this->debugging->start("Exec", $query, 6);
				}
			}

			return false;
		}
	}


	/**
	 * Returns an array of result (empty array if no results or an error occurs)
	 * Optional: Pass true to cache the result with memcache.
	 *
	 * @param string $query    SQL to execute.
	 * @param bool   $memcache Indicates if memcache should you be used if available.
	 *
	 * @return array Array of results (possibly empty) on success, empty array on failure.
	 */
	public function query($query, $memcache = false)
	{
		if (empty($query)) {
			return false;
		}

		if (nZEDb_QUERY_STRIP_WHITESPACE) {
			$query = \nzedb\utility\Utility::collapseWhiteSpace($query);
		}

		if ($memcache === true && $this->memcached === true) {
			try {
				$memcached = new Mcached();
				if ($memcached !== false) {
					$crows = $memcached->get($query);
					if ($crows !== false) {
						return $crows;
					}
				}
			} catch (Exception $e) {
				$this->echoError($e->getMessage(), 'query', 4, false, $e);

				if ($this->_debug) {
					$this->debugging->start("query", $query, 6);
				}
			}
		}

		$result = $this->queryArray($query);

		if ($memcache === true && $this->memcached === true) {
			$memcached->add($query, $result);
		}

		return ($result === false) ? array() : $result;
	}

	/**
	 * Main method for creating results as an array.
	 *
	 * @param string $query SQL to execute.
	 *
	 * @return array|boolean Array of results on success or false on failure.
	 */
	public function queryArray($query)
	{
		if (empty($query)) {
			return false;
		}

		$result = $this->queryDirect($query);
		if ($result === false) {
			return false;
		}

		$rows = array();
		foreach ($result as $row) {
			$rows[] = $row;
		}

		return (!isset($rows)) ? false : $rows;
	}

	/**
	 * Query without returning an empty array like our function query(). http://php.net/manual/en/pdo.query.php
	 *
	 * @param string $query The query to run.
	 *
	 * @return bool|PDO object
	 */
	public function queryDirect($query)
	{
		if (empty($query)) {
			return false;
		}

		if (nZEDb_QUERY_STRIP_WHITESPACE) {
			$query = \nzedb\utility\Utility::collapseWhiteSpace($query);
		}

		try {
			$result = self::$_pdo->query($query);
		} catch (\PDOException $e) {

			// Check if we lost connection to MySQL.
			if ($this->_checkGoneAway($e->getMessage()) !== false) {

				// Reconnect to MySQL.
				if ($this->_reconnect() === true) {

					// If we reconnected, retry the query.
					$result = $this->queryDirect($query);

				} else {
					// If we are not reconnected, return false.
					$result = false;
				}

			} else {
				$this->echoError($e->getMessage(), 'queryDirect', 4, false, $e);
				if ($this->_debug) {
					$this->debugging->start("queryDirect", $query, 6);
				}
				$result = false;
			}
		}
		return $result;
	}

	/**
	 * Reconnect to MySQL when the connection has been lost.
	 *
	 * @see ping(), _checkGoneAway() for checking the connection.
	 *
	 * @return bool
	 */
	protected function _reconnect()
	{
		$this->initialiseDatabase();

		// Check if we are really connected to MySQL.
		if ($this->ping() === false) {
			// If we are not reconnected, return false.
			return false;
		}
		return true;
	}

	/**
	 * Verify that we've lost a connection to MySQL.
	 *
	 * @param string $errorMessage
	 *
	 * @return bool
	 */
	protected function _checkGoneAway($errorMessage)
	{
		if (stripos($errorMessage, 'MySQL server has gone away') !== false) {
			return true;
		}
		return false;
	}

	/**
	 * Returns the first row of the query.
	 *
	 * @param string $query
	 *
	 * @return array|bool
	 */
	public function queryOneRow($query, $appendLimit = true)
	{
		// Force the query to only return 1 row, so queryArray doesn't potentially run out of memory on a large data set.
		// First check if query already contains a LIMIT clause.
		if (preg_match('#\s+LIMIT\s+(?P<lower>\d+)(,\s+(?P<upper>\d+))?(;)?$#i', $query, $matches)) {
			If (!isset($matches['upper']) && isset($matches['lower']) && $matches['lower'] == 1) {
				// good it's already correctly set.
			} else { // We have a limit, but it's not for a single row
				return false;
			}

		} else if ($appendLimit) {
			$query .= ' LIMIT 1';
		}

		$rows = $this->query($query);
		if (!$rows || count($rows) == 0) {
			$rows = false;
		}

		return is_array($rows) ? $rows[0] : $rows;
	}

	/**
	 * Returns results as an array but without an empty array like our query() function.
	 *
	 * @param string $query The query to execute.
	 *
	 * @return array|boolean Array of results on success, false otherwise.
	 */
	public function queryAssoc($query)
	{
		if ($query == '') {
			return false;
		}
		$mode = self::$_pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);
		if ($mode != \PDO::FETCH_ASSOC) {
			self::$_pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
		}

		$result = $this->queryArray($query);

		if ($mode != \PDO::FETCH_ASSOC) {
			self::$_pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
		}
		return $result;
	}

	/**
	 * Optimises/repairs tables on mysql. Vacuum/analyze on postgresql.
	 *
	 * @param bool   $admin
	 * @param string $type
	 *
	 * @return int
	 */
	public function optimise($admin = false, $type = '')
	{
		$tablecnt = 0;
		if ($this->DbSystem === 'mysql') {
			if ($type === 'true' || $type === 'full' || $type === 'analyze') {
				$alltables = $this->query('SHOW TABLE STATUS');
			} else {
				$alltables = $this->query('SHOW TABLE STATUS WHERE Data_free / Data_length > 0.005');
			}
			$tablecnt = count($alltables);
			if ($type === 'all' || $type === 'full') {
				$tbls = '';
				foreach ($alltables as $table) {
					$tbls .= $table['name'] . ', ';
				}
				$tbls = rtrim(trim($tbls),',');
				if ($admin === false) {
					$message = 'Optimizing tables: ' . $tbls;
					echo $this->log->primary($message);
					if ($this->_debug) {
						$this->debugging->start("optimise", $message, 5);
					}
				}
				$this->queryExec("OPTIMIZE LOCAL TABLE ${tbls}");
			} else {
				foreach ($alltables as $table) {
					if ($type === 'analyze') {
						if ($admin === false) {
							$message = 'Analyzing table: ' . $table['name'];
							echo $this->log->primary($message);
							if ($this->_debug) {
								$this->debugging->start("optimise", $message, 5);
							}
						}
						$this->queryExec('ANALYZE LOCAL TABLE `' . $table['name'] . '`');
					} else {
						if ($admin === false) {
							$message = 'Optimizing table: ' . $table['name'];
							echo $this->log->primary($message);
							if ($this->_debug) {
								$this->debugging->start("optimise", $message, 5);
							}
						}
						if (strtolower($table['engine']) == 'myisam') {
							$this->queryExec('REPAIR TABLE `' . $table['name'] . '`');
						}
						$this->queryExec('OPTIMIZE LOCAL TABLE `' . $table['name'] . '`');
					}
				}
			}
			if ($type !== 'analyze') {
				$this->queryExec('FLUSH TABLES');
			}
		} else if ($this->DbSystem === 'pgsql') {
			$alltables = $this->query("SELECT table_name as name FROM information_schema.tables WHERE table_schema = 'public'");
			$tablecnt = count($alltables);
			foreach ($alltables as $table) {
				if ($admin === false) {
					$message = 'Vacuuming table: ' . $table['name'] . ".\n";
					echo $message;
					if ($this->_debug) {
						$this->debugging->start("optimise", $message, 5);
					}
				}
				$this->query('VACUUM (ANALYZE) ' . $table['name']);
			}
		}
		return $tablecnt;
	}

	/**
	 * Check if the tables exists for the group_id, make new tables and set status to 1 in groups table for the id.
	 *
	 * @param int $grpid
	 *
	 * @return bool
	 */
	public function newtables($grpid)
	{
		$s = new \Sites();
		$site = $s->get();
		$DoPartRepair = ($site->partrepair == '0') ? false : true;

		if (!is_null($grpid) && is_numeric($grpid)) {
			$binaries = $parts = $collections = $partrepair = false;
			if ($this->DbSystem === 'pgsql') {
				$like = ' (LIKE collections INCLUDING ALL)';
			} else {
				$like = ' LIKE collections';
			}
			try {
				self::$_pdo->query('SELECT * FROM ' . $grpid . '_collections LIMIT 1');
				$old_tables = true;
			} catch (\PDOException $e) {
				$old_tables = false;
			}

			if ($old_tables === true) {
				$sql = 'SHOW TABLE STATUS';
				$tables = self::$_pdo->query($sql);
				if (count($tables) > 0) {
					foreach ($tables as $row) {
						$tbl = $row['name'];
						$tblnew = '';
						if (strpos($tbl, '_collections') !== false) {
							$tblnew = 'collections_' . str_replace('_collections', '', $tbl);
						} else if (strpos($tbl, '_binaries') !== false) {
							$tblnew = 'binaries_' . str_replace('_binaries', '', $tbl);
						} else if (strpos($tbl, '_parts') !== false) {
							$tblnew = 'parts_' . str_replace('_parts', '', $tbl);
						} else if (strpos($tbl, '_partrepair') !== false) {
							$tblnew = 'partrepair_' . str_replace('_partrepair', '', $tbl);
						}
						if ($tblnew != '') {
							try {
								self::$_pdo->query('ALTER TABLE ' . $tbl . ' RENAME TO ' . $tblnew);
							} catch (\PDOException $e) {
								// table already exists
							}
						}
					}
				}
			}

			try {
				self::$_pdo->query('SELECT * FROM collections_' . $grpid . ' LIMIT 1');
				$collections = true;
			} catch (\PDOException $e) {
				try {
					if ($this->queryExec('CREATE TABLE collections_' . $grpid . $like) !== false) {
						$collections = true;
						$this->newtables($grpid);
					}
				} catch (\PDOException $e) {
					return false;
				}
			}

			if ($collections === true) {
				if ($this->DbSystem === 'pgsql') {
					$like = ' (LIKE binaries INCLUDING ALL)';
				} else {
					$like = ' LIKE binaries';
				}
				try {
					self::$_pdo->query('SELECT * FROM binaries_' . $grpid . ' LIMIT 1');
					$binaries = true;
				} catch (\PDOException $e) {
					if ($this->queryExec('CREATE TABLE binaries_' . $grpid . $like) !== false) {
						$binaries = true;
						$this->newtables($grpid);
					}
				}
			}

			if ($binaries === true) {
				if ($this->DbSystem === 'pgsql') {
					$like = ' (LIKE parts INCLUDING ALL)';
				} else {
					$like = ' LIKE parts';
				}
				try {
					self::$_pdo->query('SELECT * FROM parts_' . $grpid . ' LIMIT 1');
					$parts = true;
				} catch (\PDOException $e) {
					if ($this->queryExec('CREATE TABLE parts_' . $grpid . $like) !== false) {
						$parts = true;
						$this->newtables($grpid);
					}
				}
			}

			if ($DoPartRepair === true && $parts === true) {
				if ($this->DbSystem === 'pgsql') {
					$like = ' (LIKE partrepair INCLUDING ALL)';
				} else {
					$like = ' LIKE partrepair';
				}
				try {
					self::$_pdo->query('SELECT * FROM partrepair_' . $grpid . ' LIMIT 1');
					$partrepair = true;
				} catch (\PDOException $e) {
					if ($this->queryExec('CREATE TABLE partrepair_' . $grpid . $like) !== false) {
						$partrepair = true;
						$this->newtables($grpid);
					}
				}
			} else {
				$partrepair = true;
			}

			if ($parts === true && $binaries === true && $collections === true && $partrepair === true) {
				return true;
			} else {
				return false;
			}
		}
		return false;
	}

	/**
	 * Try to create new tables for the group_id, if we fail, log the error and exit.
	 * Returns table names, with group ID if tpg is on.
	 *
	 * @param bool $tpgSetting false, tpg is off in site setting, true tpg is on in site setting.
	 * @param int  $groupID    ID of the group.
	 *
	 * @return array The table names.
	 */
	public function tryTablePerGroup($tpgSetting, $groupID)
	{
		$group['cname']  = 'collections';
		$group['bname']  = 'binaries';
		$group['pname']  = 'parts';
		$group['prname'] = 'partrepair';

		if ($tpgSetting === true) {
			if ($this->newtables($groupID) === false) {
				$this->echoError(
					'There is a problem creating new parts/files tables for this group ID: ' . $groupID,
					'tryTablePerGroup',
					1,
					true
				);
			}

			$groupEnding = '_' . $groupID;
			$group['cname']  .= $groupEnding;
			$group['bname']  .= $groupEnding;
			$group['pname']  .= $groupEnding;
			$group['prname'] .= $groupEnding;
		}
		return $group;
	}

	/**
	 * Turns off autocommit until commit() is ran. http://www.php.net/manual/en/pdo.begintransaction.php
	 *
	 * @return bool
	 */
	public function beginTransaction()
	{
		return self::$_pdo->beginTransaction();
	}

	/**
	 * Commits a transaction. http://www.php.net/manual/en/pdo.commit.php
	 *
	 * @return bool
	 */
	public function Commit()
	{
		return self::$_pdo->commit();
	}

	/**
	 * Rollback transcations. http://www.php.net/manual/en/pdo.rollback.php
	 *
	 * @return bool
	 */
	public function Rollback()
	{
		return self::$_pdo->rollBack();
	}

	/**
	 * PHP interpretation of MySQL's from_unixtime method.
	 * @param int  $utime UnixTime
	 *
	 * @return bool|string
	 */
	public function from_unixtime($utime)
	{
		if ($this->DbSystem === 'mysql') {
			return 'FROM_UNIXTIME(' . $utime . ')';
		} else {
			return 'TO_TIMESTAMP(' . $utime . ')::TIMESTAMP';
		}
	}

	/**
	 * PHP interpretation of mysql's unix_timestamp method.
	 * @param string $date
	 *
	 * @return int
	 */
	public function unix_timestamp($date)
	{
		return strtotime($date);
	}

	/**
	 * Get a string for MySQL or PgSql with a column name in between
	 * MySQL: UNIX_TIMESTAMP(column_name) AS outputName
	 * PgSQL: EXTRACT('EPOCH' FROM column_name)::INT AS outputName;
	 *
	 * @param string $column     The datetime column.
	 * @param string $outputName The name to store the SQL data into. (the word after AS)
	 *
	 * @return string
	 */
	public function unix_timestamp_column($column, $outputName = 'unix_time')
	{
		return ($this->DbSystem === 'mysql'
			?
				'UNIX_TIMESTAMP(' . $column . ') AS ' . $outputName
			:
				"EXTRACT('EPOCH' FROM " . $column . ')::INT AS ' . $outputName
		);
	}

	/**
	 * Interpretation of mysql's UUID method.
	 * Return uuid v4 string. http://www.php.net/manual/en/function.uniqid.php#94959
	 *
	 * @return string
	 */
	public function uuid()
	{
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff)
		);
	}

	/**
	 * Checks whether the connection to the server is working. Optionally restart a new connection.
	 * NOTE: Restart does not happen if PDO is not using exceptions (PHP's default configuration).
	 * In this case check the return value === false.
	 *
	 * @param boolean $restart Whether an attempt should be made to reinitialise the Db object on failure.
	 *
	 * @return boolean
	 */
	public function ping($restart = false)
	{
		try {
			return (bool) self::$_pdo->query('SELECT 1+1');
		} catch (\PDOException $e) {
			if ($restart == true) {
				$this->initialiseDatabase();
			}
			return false;
		}
	}

	/**
	 * Prepares a statement to be run by the Db engine.
	 * To run the statement use the returned $statement with ->execute();
	 *
	 * Ideally the signature would have array before $options but that causes a strict warning.
	 *
	 * @param string $query SQL query to run, with optional place holders.
	 * @param array $options Driver options.
	 *
	 * @return false|PDOstatement on success false on failure.
	 *
	 * @link http://www.php.net/pdo.prepare.php
	 */
	public function Prepare($query, $options = array())
	{
		try {
			$PDOstatement = self::$_pdo->prepare($query, $options);
		} catch (\PDOException $e) {
			if ($this->_debug) {
				$this->debugging->start("Prepare", $e->getMessage(), 5);
			}
			echo $this->log->error("\n" . $e->getMessage());
			$PDOstatement = false;
		}
		return $PDOstatement;
	}

	/**
	 * Retrieve db attributes http://us3.php.net/manual/en/pdo.getattribute.php
	 *
	 * @param int $attribute
	 *
	 * @return bool|mixed
	 */
	public function getAttribute($attribute)
	{
		if ($attribute != '') {
			try {
				$result = self::$_pdo->getAttribute($attribute);
			} catch (\PDOException $e) {
				if ($this->_debug) {
					$this->debugging->start("getAttribute", $e->getMessage(), 5);
				}
				echo $this->log->error("\n" . $e->getMessage());
				$result = false;
			}
			return $result;
		}
	}

	/**
	 * Returns the stored Db version string.
	 *
	 * @return string
	 */
	public function getDbVersion ()
	{
		return $this->dbVersion;
	}

	/**
	 * @param string $requiredVersion The minimum version to compare against
	 *
	 * @return bool|null       TRUE if Db version is greater than or eaqual to $requiredVersion,
	 * false if not, and null if the version isn't available to check against.
	 */
	public function isDbVersionAtLeast ($requiredVersion)
	{
		if (empty($this->dbVersion)) {
			return null;
		}
		return version_compare($requiredVersion, $this->dbVersion, '<=');
	}

	/**
	 * Performs the fetch from the Db server and stores the resulting Major.Minor.Version number.
	 */
	private function fetchDbVersion ()
	{
		$result = $this->queryOneRow("SELECT VERSION() AS version");
		if (!empty($result)) {
			$dummy = explode('-', $result['version'], 2);
			$this->dbVersion = $dummy[0];
		}
	}

}

// Class for caching queries into RAM using memcache.
class Mcached
{
	public $log;

	private $compression;

	private $expiry;

	private $memcache;

	// Make a connection to memcached server.
	public function __construct(array $options = array())
	{
		$defaults = array(
			'log'	=> new \ColorCLI(),
		);
		$options += $defaults;

		$this->log = $options['log'];

		if (extension_loaded('memcache')) {
			$this->memcache = new \Memcache();
			if ($this->memcache->connect(MEMCACHE_HOST, MEMCACHE_PORT) === false) {
				throw new \Exception($this->log->error("\nUnable to connect to the memcache server."));
			}
		} else {
			throw new \Exception($this->log->error("Extension 'memcache' not loaded."));
		}

		$this->expiry = MEMCACHE_EXPIRY;
		$this->compression = MEMCACHE_COMPRESSED;

		if (defined('MEMCACHE_COMPRESSION')) {
			if (MEMCACHE_COMPRESSION === false) {
				$this->compression = false;
			}
		}
	}

	// Return a SHA1 hash of the query, used for the key.
	public function key($query)
	{
		return sha1($query);
	}

	// Return some stats on the server.
	public function Server_Stats()
	{
		return $this->memcache->getExtendedStats();
	}

	// Flush all the data on the server.
	public function Flush()
	{
		return $this->memcache->flush();
	}

	// Add a query to memcached server.
	public function add($query, $result)
	{
		return $this->memcache->add($this->key($query), $result, $this->compression, $this->expiry);
	}

	// Delete a query on the memcached server.
	public function delete($query)
	{
		return $this->memcache->delete($this->key($query));
	}

	// Retrieve a query from the memcached server. Stores the query if not found.
	public function get($query)
	{
		return $this->memcache->get($this->key($query));
	}
}
