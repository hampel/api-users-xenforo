<?php namespace Tests\Unit;

use Hampel\ApiUsers\Cli\Command\Create;
use Hampel\ApiUsers\Cli\Command\ListUsers;
use Hampel\ApiUsers\Cli\Command\Validate;
use Tests\TestCase;

/**
 * XenForo loads every add-on's command classes to list them, so one that cannot load stops cmd.php
 * for the whole forum.
 */
class CommandClassesTest extends TestCase
{
	public function test_every_command_class_is_one_xenforo_can_load(): void
	{
		$classes = $this->assertConsoleCommandsLoad();

		sort($classes);
		$this->assertSame([Create::class, ListUsers::class, Validate::class], $classes);
	}

	public function test_no_command_depends_on_a_base_class_missing_from_xenforo_2_2(): void
	{
		$offenders = [];
		foreach ($this->assertConsoleCommandsLoad() AS $class)
		{
			if (in_array('XF\Cli\Command\AbstractCommand', class_parents($class), true))
			{
				$offenders[] = $class;
			}
		}

		$this->assertSame([], $offenders);
	}

	/** Command::run() is public and final in effect: a helper of that name breaks the class. */
	public function test_no_command_redefines_run(): void
	{
		$offenders = [];
		foreach ($this->assertConsoleCommandsLoad() AS $class)
		{
			if ((new \ReflectionMethod($class, 'run'))->getDeclaringClass()->getName() === $class)
			{
				$offenders[] = $class;
			}
		}

		$this->assertSame([], $offenders);
	}

	public function test_every_error_log_call_carries_the_add_on_prefix(): void
	{
		$offenders = [];
		$root = dirname(__DIR__, 2);
		foreach (['Service/HealthCheck.php', 'Cli/Command/Validate.php', 'Cli/Command/Create.php', 'Cli/Command/ListUsers.php'] AS $path)
		{
			foreach (file($root . '/' . $path) AS $number => $line)
			{
				if (preg_match('/XF::log(Error|Exception)\(/', $line) && strpos($line, 'ERROR_LOG_PREFIX') === false)
				{
					$offenders[] = $path . ':' . ($number + 1);
				}
			}
		}

		$this->assertSame([], $offenders);
	}
}
