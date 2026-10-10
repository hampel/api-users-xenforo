<?php namespace Hampel\ApiUsers\Service;

use Hampel\ApiUsers\Authentication\ApiOnly;
use XF\Service\AbstractService;

/**
 * Checks that every API user is still what this add-on made it, and that the add-on can still
 * keep it that way.
 *
 * Read-only. The entity guards refuse a password or an email address on any save, but a write
 * that bypasses the entity - fastUpdate(), raw SQL, an importer, or the user edit form while this
 * add-on was disabled - gets past them, and nothing but these checks would say so afterwards.
 *
 * Each check returns a result with a status, a label and a detail line. A check that throws is
 * reported as a problem and logged, and the rest still run.
 */
class HealthCheck extends AbstractService
{
	const OK = 'ok';
	const WARNING = 'warning';
	const PROBLEM = 'problem';

	/** the check did not apply here - never a pass, and never counted against the run */
	const SKIPPED = 'skipped';

	/** every server error log row this add-on writes begins with this */
	const ERROR_LOG_PREFIX = 'ApiUsers: ';

	const TABLE = 'xf_hampel_apiusers_user';

	/** in the order they are reported: check key => [section, label, method] */
	const CHECKS = [
		'table' => ['Environment', 'API user table', 'checkTable'],
		'extensions' => ['Environment', 'Class extensions', 'checkExtensions'],
		'listings' => ['Environment', 'Member listings', 'checkListings'],
		'accounts' => ['API users', 'Accounts', 'checkAccounts'],
		'scheme' => ['API users', 'No password', 'checkScheme'],
		'unrecorded' => ['API users', 'Unrecorded accounts', 'checkUnrecorded'],
		'email' => ['API users', 'No email address', 'checkEmail'],
		'state' => ['API users', 'Able to act', 'checkState'],
		'admins' => ['API users', 'Administrators', 'checkAdmins'],
		'keys' => ['API users', 'API keys', 'checkKeys'],
		'archive' => ['Other add-ons', 'Archive Site', 'checkArchiveSite'],
	];

	/**
	 * @return array[] each ['check' => key, 'section' => text, 'label' => text,
	 *     'status' => OK|WARNING|PROBLEM|SKIPPED, 'detail' => text]
	 */
	public function run()
	{
		$results = [];

		foreach (self::CHECKS AS $check => $definition)
		{
			list($section, $label, $method) = $definition;

			try
			{
				list($status, $detail) = $this->$method();
			}
			catch (\Throwable $e)
			{
				// one check failing must not hide the rest: this runs when something is already wrong
				\XF::logException($e, false, self::ERROR_LOG_PREFIX);

				$status = self::PROBLEM;
				$detail = 'This check could not run: ' . $e->getMessage();
			}

			$results[] = [
				'check' => $check,
				'section' => $section,
				'label' => $label,
				'status' => $status,
				'detail' => $detail,
			];
		}

		return $results;
	}

	public function checkTable()
	{
		if (!$this->db()->getSchemaManager()->tableExists(static::TABLE))
		{
			return [self::PROBLEM, static::TABLE . ' is missing, so no account is recorded as an API user.'];
		}

		return [self::OK, static::TABLE];
	}

	public function checkExtensions()
	{
		$missing = [];

		if (!($this->em()->create('XF:User') instanceof \Hampel\ApiUsers\XF\Entity\User))
		{
			$missing[] = 'XF\Entity\User';
		}
		if (!($this->em()->create('XF:UserAuth') instanceof \Hampel\ApiUsers\XF\Entity\UserAuth))
		{
			$missing[] = 'XF\Entity\UserAuth';
		}
		if (!($this->finder('XF:User') instanceof \Hampel\ApiUsers\XF\Finder\User))
		{
			$missing[] = 'XF\Finder\User';
		}

		if ($missing)
		{
			return [self::PROBLEM, 'Not applied: ' . implode(', ', $missing)
				. '. An API user can be given a password or an email address, or is listed as a member.'];
		}

		return [self::OK, 'The password guard, the email guard and the member listing filter are applied.'];
	}

	/**
	 * Proves the exclusion by reading the query, because being applied is not enough: another
	 * add-on's extension of isValidUser() that replaces the method without calling its parent
	 * discards this one's condition if it sits outside it.
	 */
	public function checkListings()
	{
		if (\XF::app() instanceof \XF\Admin\App)
		{
			return [self::SKIPPED, 'Not checked from the control panel, where API users are listed on purpose.'];
		}

		$query = $this->finder('XF:User')->isValidUser()->getQuery();

		if (strpos($query, static::TABLE) === false)
		{
			return [self::PROBLEM, 'The valid-user query does not exclude API users, so they appear in '
				. 'member lists and counts. Another add-on is probably replacing isValidUser() outside this one.'];
		}

		return [self::OK, 'The valid-user query excludes API users.'];
	}

	public function checkAccounts()
	{
		$total = $this->db()->fetchOne('SELECT COUNT(*) FROM ' . static::TABLE);
		if (!$total)
		{
			return [self::WARNING, 'There are no API users. Create one with api-users:create or under Setup > API users.'];
		}

		$orphans = $this->db()->fetchAllColumn('
			SELECT api_user.user_id
			FROM ' . static::TABLE . ' AS api_user
			LEFT JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			WHERE user.user_id IS NULL
		');
		if ($orphans)
		{
			return [self::WARNING, $total . ' recorded, of which ' . count($orphans)
				. ' name an account that no longer exists: user_id ' . implode(', ', $orphans) . '.'];
		}

		return [self::OK, $total . ' recorded.'];
	}

	public function checkScheme()
	{
		$names = $this->db()->fetchAllColumn('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_user_authenticate AS auth ON (auth.user_id = api_user.user_id)
			WHERE auth.scheme_class IS NULL OR auth.scheme_class <> ?
			ORDER BY user.username
		', ApiOnly::NAME);

		if ($names)
		{
			return [self::PROBLEM, 'Not on the API-only scheme, so possibly able to log in: ' . implode(', ', $names) . '.'];
		}

		return [self::OK, 'Every API user is on the API-only scheme.'];
	}

	public function checkUnrecorded()
	{
		$names = $this->db()->fetchAllColumn('
			SELECT user.username
			FROM xf_user_authenticate AS auth
			INNER JOIN xf_user AS user ON (user.user_id = auth.user_id)
			LEFT JOIN ' . static::TABLE . ' AS api_user ON (api_user.user_id = auth.user_id)
			WHERE auth.scheme_class = ? AND api_user.user_id IS NULL
			ORDER BY user.username
		', ApiOnly::NAME);

		if ($names)
		{
			return [self::PROBLEM, 'On the API-only scheme but not recorded as API users, so neither hidden '
				. 'nor guarded: ' . implode(', ', $names) . '.'];
		}

		return [self::OK, 'No account is on the API-only scheme without being recorded.'];
	}

	public function checkEmail()
	{
		$names = $this->db()->fetchAllColumn('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			WHERE user.email <> \'\'
			ORDER BY user.username
		');

		if ($names)
		{
			return [self::PROBLEM, 'Has an email address: ' . implode(', ', $names) . '.'];
		}

		return [self::OK, 'No API user has an email address.'];
	}

	/**
	 * The REST API resolves a key whose user is not valid, or is banned, to the guest permission
	 * combination. A warning and not a failure: banning an API user is also how one is suspended.
	 */
	public function checkState()
	{
		$names = $this->db()->fetchAllColumn('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			WHERE user.user_state <> \'valid\' OR user.is_banned = 1
			ORDER BY user.username
		');

		if ($names)
		{
			return [self::WARNING, 'Banned or not in the valid state, so the API treats its key as a guest: '
				. implode(', ', $names) . '.'];
		}

		return [self::OK, 'Every API user is valid and not banned.'];
	}

	public function checkAdmins()
	{
		$names = $this->db()->fetchAllColumn('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_admin AS admin ON (admin.user_id = api_user.user_id)
			WHERE user.is_admin = 1 AND admin.user_id IS NULL
			ORDER BY user.username
		');

		if ($names)
		{
			return [self::PROBLEM, 'Flagged as an administrator with no administrator record: ' . implode(', ', $names) . '.'];
		}

		$admins = $this->db()->fetchOne('
			SELECT COUNT(*)
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_admin AS admin ON (admin.user_id = api_user.user_id)
		');

		return [self::OK, $admins . ' of the API users ' . ($admins == 1 ? 'is an administrator.' : 'are administrators.')];
	}

	public function checkKeys()
	{
		$names = $this->db()->fetchAllColumn('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_api_key AS api_key ON (api_key.user_id = api_user.user_id AND api_key.active = 1)
			WHERE api_key.api_key_id IS NULL
			ORDER BY user.username
		');

		if ($names)
		{
			return [self::WARNING, 'No active API key names: ' . implode(', ', $names) . '. Such an account can do nothing.'];
		}

		if (!$this->db()->fetchOne('SELECT COUNT(*) FROM ' . static::TABLE))
		{
			return [self::SKIPPED, 'There are no API users.'];
		}

		return [self::OK, 'Every API user is named by an active API key.'];
	}

	/**
	 * Hampel/ArchiveSite archives every account it has not been told to protect: it removes the
	 * password, which the guard here refuses - stopping the archive run at that account - and
	 * disables the account, which would stop its API key working. Its protected list is the
	 * supported way to keep an API user out of a run.
	 *
	 * Reads that add-on's config.php keys and nothing else of it; this is not a dependency.
	 */
	public function checkArchiveSite()
	{
		if (!$this->archiveSiteInstalled())
		{
			return [self::SKIPPED, 'Not installed.'];
		}

		$protected = $this->archiveSiteProtectedUserIds();
		$placeholders = $protected ? ' AND api_user.user_id NOT IN (' . $this->db()->quote($protected) . ')' : '';

		$unprotected = $this->db()->fetchPairs('
			SELECT user.user_id, user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_admin AS admin ON (admin.user_id = api_user.user_id)
			WHERE (admin.is_super_admin IS NULL OR admin.is_super_admin = 0)' . $placeholders . '
			ORDER BY user.username
		');

		if ($unprotected)
		{
			$list = [];
			foreach ($unprotected AS $userId => $username)
			{
				$list[] = "{$username} ({$userId})";
			}

			return [self::WARNING, 'Not protected from an archive run, which would stop at the account: '
				. implode(', ', $list) . ". Add each user_id to \$config['archiveSite']['protectedUsers']."];
		}

		return [self::OK, 'Every API user is protected from an archive run.'];
	}

	/**
	 * @return bool
	 */
	protected function archiveSiteInstalled()
	{
		$addOns = \XF::app()->container('addon.cache');

		return isset($addOns['Hampel/ArchiveSite']);
	}

	/**
	 * @return int[]
	 */
	protected function archiveSiteProtectedUserIds()
	{
		$settings = \XF::config('archiveSite');
		$ids = is_array($settings) && isset($settings['protectedUsers'])
			? $settings['protectedUsers']
			: \XF::config('archiveSiteProtectedUsers');

		if (!$ids)
		{
			return [];
		}

		return array_map('intval', (array) $ids);
	}
}
