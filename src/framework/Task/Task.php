<?php

namespace Framework\Task;

use Amp\Deferred;
use Amp\Promise;
use Framework\Base\Channel;
use Framework\Utils\StrUtil;
use function Amp\call;

class Task
{

	private static $pid = '';
	private static $eventId = 0;

	/**
	 * Execute and get return in TaskWorker
	 * 
	 * @param callable $callable Executor callable, allow coroutine
	 * @param mixed ...$args
	 * @return Promise
	 */
	public static function execute($callable, ...$args)
	{
		if ($callable instanceof \Closure) {
			throw new \Exception('Can not run closure in Task!');
		}

		if (is_array($callable) && is_object($callable[0])) {
			$callable[0] = get_class($callable[0]);
		}

		return call(static function() use ($callable, $args) {
			$id = self::eventId();
			$defer = new Deferred();
			$returnEvent = Task::class.'@'.$id;

			$channel = di()->get(Channel::class);

            $channel->on($returnEvent, static function($data) use ($defer, $returnEvent, $channel) {
                $channel->unsubscribe($returnEvent);
				list($state, $return) = $data;
				if ($state) {
					$defer->resolve($return);
				} else {
					if (class_exists($return['exception'])) {
						$defer->fail(new $return['exception']($return['message'], $return['code']));
					} else {
						$defer->fail(new \Exception($return['message'], $return['code']));
					}
				}
			});

            $channel->enqueue(Task::class, ['id'=>$id, 'callable'=>$callable, 'args'=>$args]);

			return $defer->promise();
		});
	}

	private static function eventId()
	{
		self::$eventId++;

		if (self::$pid === '') {
			self::$pid = StrUtil::randomString(8);
		}

		return self::$pid.'-'.self::$eventId;
	}

}