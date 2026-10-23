<?php namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Development files that a release must leave out. XenForo's builder ships whatever is in the
 * add-on directory unless build.json removes it, and nothing else notices when it does not.
 */
class PackagingTest extends TestCase
{
	private const DEVELOPMENT_ONLY = ['Seed', 'Factory', 'tests', 'vendor', 'TESTING.md', 'CLAUDE.md', 'CLAUDE.local.md', 'phpstan.neon'];

	/**
	 * @param string $path
	 *
	 * @return string
	 */
	private function read($path)
	{
		$contents = file_get_contents($path);
		$this->assertIsString($contents, "Could not read {$path}");

		return $contents;
	}

	public function test_build_json_removes_every_development_only_path_before_it_moves_the_documents(): void
	{
		$build = json_decode($this->read(dirname(__DIR__, 2) . '/build.json'), true);
		$this->assertIsArray($build);
		$steps = $build['exec'] ?? null;
		$this->assertIsArray($steps);
		$this->assertContainsOnlyString($steps);

		$moves = array_keys(array_filter($steps, function ($step) { return strpos($step, 'mv ') === 0; }));
		$this->assertNotEmpty($moves);

		// a removal placed after the move deletes nothing: the file has already gone to the zip root
		$removals = implode("\n", array_filter(array_slice($steps, 0, $moves[0]), function ($step)
		{
			return strpos($step, 'rm ') === 0;
		}));

		$missing = [];
		foreach (self::DEVELOPMENT_ONLY AS $path)
		{
			if (!preg_match('#/\{addon_id\}/' . preg_quote($path, '#') . '(\s|$)#m', $removals))
			{
				$missing[] = $path;
			}
		}

		$this->assertSame([], $missing);
	}

	/** The seeder classes need another add-on, so nothing this add-on ships may refer to them. */
	public function test_nothing_outside_the_development_directories_refers_to_them(): void
	{
		$root = dirname(__DIR__, 2);
		$referring = [];

		foreach (['Admin', 'Authentication', 'Cli', 'Entity', 'Repository', 'Service', 'XF'] AS $directory)
		{
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));
			foreach ($files AS $file)
			{
				$this->assertInstanceOf(\SplFileInfo::class, $file);
				if (preg_match('/Hampel\\\\(Seeder|ApiUsers\\\\(Seed|Factory))\\\\/', $this->read($file->getPathname())))
				{
					$referring[] = substr($file->getPathname(), strlen($root) + 1);
				}
			}
		}
		foreach (['Listener.php', 'Setup.php'] AS $file)
		{
			if (preg_match('/Hampel\\\\(Seeder|ApiUsers\\\\(Seed|Factory))\\\\/', $this->read("{$root}/{$file}")))
			{
				$referring[] = $file;
			}
		}

		$this->assertSame([], $referring);
	}
}
