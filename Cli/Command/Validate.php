<?php namespace Hampel\ApiUsers\Cli\Command;

use Hampel\ApiUsers\Cli\RendersReport;
use Hampel\ApiUsers\Service\HealthCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether every API user is still API-only, and whether this add-on can still keep it so.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail, so a gate can rely on
 * it. --strict makes a warning exit 2, for a monitor that wants to hear of one, and changes
 * nothing else. This is not the monitoring-plugin numbering, where 1 is a warning.
 *
 * **This add-on writes nothing and sends nothing here, so --unattended has nothing to skip.** It
 * is accepted anyway, so that a deploy or monitor calling `<addon>:validate --unattended` across
 * every add-on on a forum does not fail here on an unknown option.
 */
class Validate extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('api-users:validate')
			->setDescription('Check that every API user still has no password or email address, and is hidden and able to act')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Accepted for consistency with other add-ons; this one neither writes nor sends, so it changes nothing')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings and no failures, instead of 0');
	}

	protected function errorLogPrefix(): string
	{
		return HealthCheck::ERROR_LOG_PREFIX;
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		/** @var HealthCheck $healthCheck */
		$healthCheck = \XF::service('Hampel\ApiUsers:HealthCheck');

		$section = null;
		foreach ($healthCheck->run() AS $result)
		{
			if ($result['section'] !== $section)
			{
				$section = $result['section'];
				$this->checkSection($section);
			}

			switch ($result['status'])
			{
				case HealthCheck::OK: $this->checkOk($result['label'], $result['detail']); break;
				case HealthCheck::WARNING: $this->checkWarn($result['label'], $result['detail']); break;
				case HealthCheck::PROBLEM: $this->checkFail($result['label'], $result['detail']); break;
				default: $this->checkSkip($result['label'], $result['detail']);
			}
		}

		$output->writeln('');

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}
}
