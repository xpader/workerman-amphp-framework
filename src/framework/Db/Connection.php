<?php

namespace Framework\Db;

use Amp\Mysql\CommandResult;
use Amp\Mysql\ConnectionConfig;
use Amp\Promise;
use Amp\Sql\Common\ConnectionPool;
use Framework\Base\Config;
use Workerman\Worker;
use function Amp\call;
use function Amp\Mysql\pool;

/**
 * Database base Connection and Fetch
 * @package Framework\Db
 */
class Connection
{

	/**
	 * @var \Amp\Mysql\Pool
	 */
	private $pool;

	private $name;

	private $prefix = '';

    /**
     * Set fetchAll() return array index is used by special result key
     *
     * @var string
     */
    protected $indexBy;

	public function __construct($name) {
		$databases = di()->get(Config::class)->get('database');

		if (!isset($databases[$name])) {
			throw new \Exception("Unable to find database config '{$name}'.");
		}

		$config = $databases[$name];

		//初始化数据库连接池
        $conn = new ConnectionConfig(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['database']
        );

		if (isset($config['charset'])) {
            $conn->withCharset($config['charset'], $config['collation']);
        }

		$maxConnection = $connection['pool']['max_connections'] ?? ConnectionPool::DEFAULT_MAX_CONNECTIONS;
		$maxIdleTime = $connection['pool']['max_idle_time'] ?? ConnectionPool::DEFAULT_IDLE_TIMEOUT;

		$this->pool = pool($conn, $maxConnection, $maxIdleTime);
		$this->name = $name;
		$this->prefix = $config['prefix'];
	}

	public function prefix($table='')
    {
        return $table ? $this->prefix.$table : $this->prefix;
    }

    /**
     * Construct a query build from table
     *
     * @param string $name
     * @return QueryBuilder
     */
    public function table($name)
    {
        return (new QueryBuilder($this))->from($name);
    }

	/**
	 * @param string $sql
	 * @param array $params
	 * @return Promise<\Amp\Mysql\ResultSet>
	 * @throws \Amp\Sql\ConnectionException
	 * @throws \Amp\Sql\FailureException
	 */
	public function query(string $sql, array $params=[]): Promise
	{
        Worker::log($sql);
		if ($params) {
			return call(function() use ($sql, $params) {
				$statement = yield $this->pool->prepare($sql);
				return yield $statement->execute($params);
			});
		} else {
			return $this->pool->query($sql);
		}
	}

	/**
	 * @param string $sql
	 * @param array $params
	 * @return Promise<CommandResult>
	 * @throws \Amp\Sql\ConnectionException
	 * @throws \Amp\Sql\FailureException
	 */
	public function execute(string $sql, array $params = []): Promise
	{
	    Worker::log($sql);
		return $this->pool->execute($sql, $params);
	}

	/**
	 * 查询一条数据出来
	 *
	 * @param string $sql
	 * @param array $params
	 * @return Promise<array>
	 */
	public function fetchOne($sql, array $params=[]): Promise {
		return call(function() use ($sql, $params) {
			$result = yield $this->query($sql, $params);

			if (yield $result->advance()) {
				$row = $result->getCurrent();
				//必须持续调用 nextResultSet 或 advance 直到无数据为止
				//防止资源未释放时后面的查询建立新连接的问题
				//如果查询出的数据行数大于一条，则仍然可能出现此问题
				yield $result->nextResultSet();
				return $row;
			} else {
				return null;
			}
		});
	}

	/**
	 * 查询出全部数据
	 *
	 * @param string $sql
	 * @param array $params
	 * @return Promise<array>
	 */
	public function fetchAll($sql, array $params=[]): Promise {
		return call(function() use ($sql, $params) {
			$result = yield $this->query($sql, $params);

			$rows = [];

			while (yield $result->advance()) {
				$row = $result->getCurrent();
				if (!$this->indexBy) {
                    $rows[] = $row;
                } else {
                    if (!isset($row[$this->indexBy])) {
                        throw new DbException("Undefined indexBy key '{$this->indexBy}'.");
                    }
                    $rows[$row[$this->indexBy]] = $row;
                }
			}

            $this->indexBy = null;

			return $rows;
		});
	}

    /**
     * Set key for fetchAll() return array
     *
     * @param string $key
     * @return $this
     */
    public function indexBy($key)
    {
        $this->indexBy = $key;
        return $this;
    }

    /**
     * Fetch column from all rows
     *
     * @param $sql
     * @param array $params
     * @param int $col
     * @return Promise
     */
    public function fetchColumn($sql, array $params=[], $col=0): Promise {
        return call(function() use ($sql, $params, $col) {
            $cols = [];
            $result = yield $this->query($sql, $params);

            while (yield $result->advance()) {
                $row = $result->getCurrent();
                if ($this->indexBy) {
                    $cols[$row[$this->indexBy]] = $row[$col];
                } else {
                    $cols[] = $row[$col];
                }
            }

            $this->indexBy = null;

            return $cols;
        });
    }

}