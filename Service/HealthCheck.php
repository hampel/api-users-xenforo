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
	/** @var string */
	const OK = 'ok';
	/** @var string */
	const WARNING = 'warning';
	/** @var string */
	const PROBLEM = 'problem';

	/** @var string the check did not apply here - never a pass, and never counted against the run */
	const SKIPPED = 'skipped';

	/** @var string every server error log row this add-on writes begins with this */
	const ERROR_LOG_PREFIX = 'ApiUsers: ';

	/** @var string */
	const TABLE = 'xf_hampel_apiusers_user';

	/** in the order they are reported: check key => [section, label, method] */
	const CHECKS = [
		'table' => ['Environment', 'API user table', 'checkTable'],
		'extensions' => ['Environment', 'Class extensions', 'checkExtensions'],
		'listings' => ['Environment', 'Member listings', 'checkListings'],
		'modifications' => ['Environment', 'User edit page', 'checkModifications'],
		'accounts' => ['API users', 'Accounts', 'checkAccounts'],
		'scheme' => ['API users', 'No password', 'checkScheme'],
		'unrecorded' => ['API users', 'Unrecorded accounts', 'checkUnrecorded'],
		'logins' => ['API users', 'No other way in', 'checkOtherLogins'],
		'follows' => ['API users', 'No follows', 'checkFollows'],
		'email' => ['API users', 'No email address', 'checkEmail'],
		'state' => ['API users', 'Able to act', 'checkState'],
		'admins' => ['API users', 'Administrators', 'checkAdmins'],
		'keys' => ['API users', 'API keys', 'checkKeys'],
		'archive' => ['Other add-ons', 'Archive Site', 'checkArchiveSite'],
	];

	/**
	 * @return list<array{check: string, section: string, label: string, status: string, detail: string}>
	 *     status is one of OK, WARNING, PROBLEM and SKIPPED
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

				$status = static::PROBLEM;
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

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkTable()
	{
		if (!$this->db()->getSchemaManager()->tableExists(static::TABLE))
		{
			return [static::PROBLEM, static::TABLE . ' is missing, so no account is recorded as an API user.'];
		}

		return [static::OK, static::TABLE];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkExtensions()
	{
		$missing = [];

		if (!$this->isExtendedBy($this->em()->create('XF:User'), \Hampel\ApiUsers\XF\Entity\User::class))
		{
			$missing[] = 'XF\Entity\User';
		}
		if (!$this->isExtendedBy($this->em()->create('XF:UserAuth'), \Hampel\ApiUsers\XF\Entity\UserAuth::class))
		{
			$missing[] = 'XF\Entity\UserAuth';
		}
		if (!$this->isExtendedBy($this->finder('XF:User'), \Hampel\ApiUsers\XF\Finder\User::class))
		{
			$missing[] = 'XF\Finder\User';
		}

		if (!$this->isExtendedBy($this->service('XF:User\Merge'), \Hampel\ApiUsers\XF\Service\User\Merge::class))
		{
			$missing[] = 'XF\Service\User\Merge';
		}

		if ($missing)
		{
			return [static::PROBLEM, 'Not applied: ' . implode(', ', $missing)
				. '. An API user can be given a password or an email address, is listed as a member, or can be merged.'];
		}

		return [static::OK, 'The password guard, the email guard, the member listing filter and the merge refusal are applied.'];
	}

	/**
	 * Whether the object the container built is this add-on's extension. A method of its own,
	 * because static analysis types the container's answer as the extension already, which is the
	 * very thing this asks at runtime.
	 *
	 * @param object $object
	 * @param string $extension
	 *
	 * @return bool
	 */
	protected function isExtendedBy($object, $extension)
	{
		return $object instanceof $extension;
	}

	/**
	 * Proves the exclusion by reading the query, because being applied is not enough: another
	 * add-on's extension of isValidUser() that replaces the method without calling its parent
	 * discards this one's condition if it sits outside it.
	 *
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkListings()
	{
		if (\XF::app() instanceof \XF\Admin\App)
		{
			return [static::SKIPPED, 'Not checked from the control panel, where API users are listed on purpose.'];
		}

		// a query that cannot be read is reported as not excluding them
		$query = $this->text($this->finder('XF:User')->isValidUser()->getQuery());

		if (strpos($query, static::TABLE) === false)
		{
			return [static::PROBLEM, 'The valid-user query does not exclude API users, so they appear in '
				. 'member lists and counts. Another add-on is probably replacing isValidUser() outside this one.'];
		}

		return [static::OK, 'The valid-user query excludes API users.'];
	}

	/**
	 * The modifications to core's user edit page match text in a core template, so a XenForo
	 * release that rewords it stops one matching and nothing errors. A warning: the entity guards
	 * do not depend on them, only what an administrator is shown.
	 *
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkModifications()
	{
		$mods = $this->db()->fetchAll('
			SELECT m.modification_key, m.enabled, COALESCE(SUM(l.apply_count), 0) AS applied
			FROM xf_template_modification AS m
			LEFT JOIN xf_template_modification_log AS l ON (l.modification_id = m.modification_id)
			WHERE m.addon_id = ?
			GROUP BY m.modification_id
		', 'Hampel/ApiUsers');

		if (!$mods)
		{
			return [static::WARNING, 'None registered: the user edit page neither marks an API user nor offers to convert one.'];
		}

		$dead = [];
		foreach ($mods AS $mod)
		{
			if (empty($mod['enabled']) || empty($mod['applied']))
			{
				$dead[] = $this->text($mod['modification_key'] ?? '');
			}
		}

		if ($dead)
		{
			return [static::WARNING, 'Disabled or matching nothing in the user edit template: ' . implode(', ', $dead) . '.'];
		}

		return [static::OK, count($mods) . ' modifications applying.'];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkAccounts()
	{
		$total = $this->number($this->db()->fetchOne('SELECT COUNT(*) FROM ' . static::TABLE));
		if (!$total)
		{
			return [static::WARNING, 'There are no API users. Create one with api-users:create or under Setup > API users.'];
		}

		$orphans = $this->fetchText('
			SELECT api_user.user_id
			FROM ' . static::TABLE . ' AS api_user
			LEFT JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			WHERE user.user_id IS NULL
		');
		if ($orphans)
		{
			return [static::WARNING, $total . ' recorded, of which ' . count($orphans)
				. ' name an account that no longer exists: user_id ' . implode(', ', $orphans) . '.'];
		}

		return [static::OK, $total . ' recorded.'];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkScheme()
	{
		$names = $this->fetchText('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_user_authenticate AS auth ON (auth.user_id = api_user.user_id)
			WHERE auth.scheme_class IS NULL OR auth.scheme_class <> ?
			ORDER BY user.username
		', ApiOnly::NAME);

		if ($names)
		{
			return [static::PROBLEM, 'Not on the API-only scheme, so possibly able to log in: ' . implode(', ', $names) . '.'];
		}

		return [static::OK, 'Every API user is on the API-only scheme.'];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkUnrecorded()
	{
		$names = $this->fetchText('
			SELECT user.username
			FROM xf_user_authenticate AS auth
			INNER JOIN xf_user AS user ON (user.user_id = auth.user_id)
			LEFT JOIN ' . static::TABLE . ' AS api_user ON (api_user.user_id = auth.user_id)
			WHERE auth.scheme_class = ? AND api_user.user_id IS NULL
			ORDER BY user.username
		', ApiOnly::NAME);

		if ($names)
		{
			return [static::PROBLEM, 'On the API-only scheme but not recorded as API users, so neither hidden '
				. 'nor guarded: ' . implode(', ', $names) . '.'];
		}

		return [static::OK, 'No account is on the API-only scheme without being recorded.'];
	}

	/**
	 * A connected account or a passkey logs its user in whatever the authentication scheme says.
	 *
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkOtherLogins()
	{
		$db = $this->db();
		$sm = $db->getSchemaManager();

		$names = $this->fetchText('
			SELECT DISTINCT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			INNER JOIN xf_user_connected_account AS connected ON (connected.user_id = api_user.user_id)
			ORDER BY user.username
		');

		// XenForo 2.3 only
		if ($sm->tableExists('xf_passkey'))
		{
			$names = array_unique(array_merge($names, $this->fetchText('
				SELECT DISTINCT user.username
				FROM ' . static::TABLE . ' AS api_user
				INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
				INNER JOIN xf_passkey AS passkey ON (passkey.user_id = api_user.user_id)
				ORDER BY user.username
			')));
		}

		if ($names)
		{
			return [static::PROBLEM, 'Has a connected account or a passkey, so can log in without a password: '
				. implode(', ', $names) . '.'];
		}

		return [static::OK, 'No API user has a connected account or a passkey.'];
	}

	/**
	 * A follow names both accounts on each other's public profile. Converting removes them and
	 * nothing new can be made through XenForo, so one here was written some other way.
	 *
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkFollows()
	{
		$names = $this->fetchText('
			SELECT DISTINCT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			INNER JOIN xf_user_follow AS follow
				ON (follow.user_id = api_user.user_id OR follow.follow_user_id = api_user.user_id)
			ORDER BY user.username
		');

		if ($names)
		{
			return [static::WARNING, 'Follows a member or is followed by one, which names it on that member\'s public profile: '
				. implode(', ', $names) . '.'];
		}

		return [static::OK, 'No API user follows a member or is followed by one.'];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkEmail()
	{
		$names = $this->fetchText('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			WHERE user.email <> \'\'
			ORDER BY user.username
		');

		if ($names)
		{
			return [static::PROBLEM, 'Has an email address: ' . implode(', ', $names) . '.'];
		}

		return [static::OK, 'No API user has an email address.'];
	}

	/**
	 * The REST API resolves a key whose user is not valid, or is banned, to the guest permission
	 * combination. A warning and not a failure: banning an API user is also how one is suspended.
	 *
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkState()
	{
		$names = $this->fetchText('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			WHERE user.user_state <> \'valid\' OR user.is_banned = 1
			ORDER BY user.username
		');

		if ($names)
		{
			return [static::WARNING, 'Banned or not in the valid state, so the API treats its key as a guest: '
				. implode(', ', $names) . '.'];
		}

		return [static::OK, 'Every API user is valid and not banned.'];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkAdmins()
	{
		$names = $this->fetchText('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_admin AS admin ON (admin.user_id = api_user.user_id)
			WHERE user.is_admin = 1 AND admin.user_id IS NULL
			ORDER BY user.username
		');

		if ($names)
		{
			return [static::PROBLEM, 'Flagged as an administrator with no administrator record: ' . implode(', ', $names) . '.'];
		}

		$admins = $this->number($this->db()->fetchOne('
			SELECT COUNT(*)
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_admin AS admin ON (admin.user_id = api_user.user_id)
		'));

		return [static::OK, $admins . ' of the API users ' . ($admins == 1 ? 'is an administrator.' : 'are administrators.')];
	}

	/**
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkKeys()
	{
		$names = $this->fetchText('
			SELECT user.username
			FROM ' . static::TABLE . ' AS api_user
			INNER JOIN xf_user AS user ON (user.user_id = api_user.user_id)
			LEFT JOIN xf_api_key AS api_key ON (api_key.user_id = api_user.user_id AND api_key.active = 1)
			WHERE api_key.api_key_id IS NULL
			ORDER BY user.username
		');

		if ($names)
		{
			return [static::WARNING, 'No active API key names: ' . implode(', ', $names) . '. Such an account can do nothing.'];
		}

		if (!$this->db()->fetchOne('SELECT COUNT(*) FROM ' . static::TABLE))
		{
			return [static::SKIPPED, 'There are no API users.'];
		}

		return [static::OK, 'Every API user is named by an active API key.'];
	}

	/**
	 * Hampel/ArchiveSite archives every account it has not been told to protect: it removes the
	 * password, which the guard here refuses - stopping the archive run at that account - and
	 * disables the account, which would stop its API key working. Its protected list is the
	 * supported way to keep an API user out of a run.
	 *
	 * Reads that add-on's config.php keys and nothing else of it; this is not a dependency.
	 *
	 * @return array{0: string, 1: string} a status, and the detail shown beside it
	 */
	public function checkArchiveSite()
	{
		if (!$this->archiveSiteInstalled())
		{
			return [static::SKIPPED, 'Not installed.'];
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
				$list[] = $this->text($username) . " ({$userId})";
			}

			return [static::WARNING, 'Not protected from an archive run, which would stop at the account: '
				. implode(', ', $list) . ". Add each user_id to \$config['archiveSite']['protectedUsers']."];
		}

		return [static::OK, 'Every API user is protected from an archive run.'];
	}

	/**
	 * @return bool
	 */
	protected function archiveSiteInstalled()
	{
		$addOns = \XF::app()->container('addon.cache');

		return is_array($addOns) && isset($addOns['Hampel/ArchiveSite']);
	}

	/**
	 * One column of a query, as text: every caller puts the values into a sentence.
	 *
	 * @param string $sql
	 * @param mixed $params
	 *
	 * @return string[]
	 */
	protected function fetchText($sql, $params = [])
	{
		return array_map([$this, 'text'], $this->db()->fetchAllColumn($sql, $params));
	}

	/**
	 * A database value as text. Every value here is a scalar or null; anything else is empty.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function text($value)
	{
		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * A database value as a count or an id.
	 *
	 * @param mixed $value
	 *
	 * @return int
	 */
	protected function number($value)
	{
		return is_numeric($value) ? (int) $value : 0;
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

		return array_map([$this, 'number'], (array) $ids);
	}
}
