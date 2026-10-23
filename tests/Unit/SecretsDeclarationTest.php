<?php namespace Tests\Unit;

use Hampel\ApiUsers\Listener;
use Tests\TestCase;

/**
 * What this add-on tells the Admin API's redaction event about itself. A declaration goes stale
 * without anything failing, so each test is a tripwire for the day the add-on gains a setting.
 */
class SecretsDeclarationTest extends TestCase
{
	/**
	 * @return array<string, mixed>
	 */
	private function declarations()
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	public function test_it_declares_this_add_on_only(): void
	{
		$this->assertSame(['Hampel/ApiUsers'], array_keys($this->declarations()));
	}

	public function test_it_has_no_options_to_declare(): void
	{
		$options = glob(dirname(__DIR__, 2) . '/_output/options/*.json') ?: [];

		$this->assertSame([], $options, 'this add-on has gained an option: decide whether it is a secret and declare it');
		$this->assertSame([], $this->declarations()['Hampel/ApiUsers']);
	}

	/** config.php is read for Hampel/ArchiveSite's protected list, which that add-on declares. */
	public function test_it_reads_no_config_key_of_its_own(): void
	{
		$readers = [];
		$root = dirname(__DIR__, 2);
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($files AS $file)
		{
			$this->assertInstanceOf(\SplFileInfo::class, $file);
			$path = substr($file->getPathname(), strlen($root) + 1);
			if (substr($path, -4) !== '.php' || preg_match('#^(vendor|tests|_output|_data|_releases)/#', $path))
			{
				continue;
			}
			$source = file_get_contents($file->getPathname());
			$this->assertIsString($source, $path);
			if (preg_match_all('/XF::config\(\'([^\']+)\'\)/', $source, $matches))
			{
				foreach ($matches[1] AS $key)
				{
					$readers[$key][] = $path;
				}
			}
		}

		$this->assertSame(['archiveSite', 'archiveSiteProtectedUsers'], array_keys($readers));
	}
}
