<?php namespace Hampel\ApiUsers\Factory;

use Hampel\Seeder\Factory\AbstractFactory;
use Hampel\Seeder\Factory\UserFactory;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * An API user, made by the same service the control panel and the command line use, so a seeded
 * one is exactly what an administrator would have created.
 *
 * Development only: needs the Hampel/Seeder add-on, which is not a dependency of this one, and is
 * left out of a release. Its method signatures are typed because that add-on's base class types
 * them, which the rest of this add-on does not do.
 *
 * The entity built and recorded is the account, not this add-on's own row: removing the account
 * removes the row with it, and removing the row alone would leave the account behind.
 *
 *     ApiUserFactory::new()->count(5)->administrator()->has(ApiKeyFactory::new())->create();
 *
 * | attribute | |
 * |---|---|
 * | `username` | a made-up name no account has |
 * | `note` | what the account is for |
 * | `admin` | true to make it an administrator holding no permissions |
 * | `created_by` | the user id of the administrator it is recorded against; 0 is the command line |
 * | `created_date` | when it was made |
 */
class ApiUserFactory extends AbstractFactory
{
	protected $entity = 'XF:User';

	protected const ROLES = ['Sync', 'Monitor', 'Importer', 'Feed', 'Reporter', 'Bridge', 'Bot', 'Exporter'];

	/** @var array [spl object id of the account => the service that will save it] */
	private static $creators = [];

	/** @var array [spl object id of the account => when it was made] */
	private static $dates = [];

	/** @var array names handed out in this process and not yet saved */
	private static $taken = [];

	public function definition()
	{
		$faker = $this->context()->faker;

		return [
			'username' => $this->uniqueUsername(),
			'note' => rtrim($faker->sentence(5), '.'),
			'admin' => false,
			'created_by' => 0,
			'created_date' => $this->context()->timeline->at(),
		];
	}

	/**
	 * Also an administrator, holding no admin permissions.
	 *
	 * @return static
	 */
	public function administrator()
	{
		return $this->state(['admin' => true]);
	}

	/**
	 * Recorded as made by this administrator, not from the command line.
	 *
	 * @return static
	 */
	public function createdBy(User $user)
	{
		return $this->state(['created_by' => $user->user_id]);
	}

	public static function describe(Entity $entity): array
	{
		return [
			'username' => $entity->username,
			'administrator' => (bool) $entity->is_admin,
			'note' => $entity->ApiUser ? $entity->ApiUser->note : null,
		];
	}

	/**
	 * Deleting the account is what removes this add-on's row, and the member factory already
	 * knows everything else a deleted account leaves behind.
	 */
	public static function remove(Entity $entity): void
	{
		UserFactory::remove($entity);
	}

	protected function newEntity(array $attributes): Entity
	{
		/** @var \Hampel\ApiUsers\Service\ApiUser\Creator $creator */
		$creator = \XF::service('Hampel\ApiUsers:ApiUser\Creator', $attributes['username']);
		$creator->setNote($attributes['note']);
		$creator->setAdmin($attributes['admin']);

		$createdBy = $attributes['created_by'] ? \XF::em()->find('XF:User', $attributes['created_by']) : null;
		if ($createdBy)
		{
			$creator->setCreatedBy($createdBy);
		}

		$user = $creator->getUser();
		$user->register_date = $attributes['created_date'];
		$user->last_activity = $attributes['created_date'];

		self::$creators[spl_object_id($user)] = $creator;
		self::$dates[spl_object_id($user)] = $attributes['created_date'];

		return $user;
	}

	protected function store(Entity $entity): void
	{
		$id = spl_object_id($entity);
		$creator = self::$creators[$id];
		unset(self::$creators[$id], self::$dates[$id], self::$taken[strtolower($entity->username)]);

		if (!$creator->validate($errors))
		{
			throw new \RuntimeException(
				"An API user named '{$entity->username}' could not be made: " . implode(' ', array_map('strval', $errors))
			);
		}

		$creator->save();
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$dates[spl_object_id($entity)] ?? null;
	}

	/**
	 * A name in the manner of a service account, which no account has in the database or in this
	 * run and which this forum accepts.
	 */
	protected function uniqueUsername(): string
	{
		$faker = $this->context()->faker;

		for ($attempt = 0; $attempt < 50; $attempt++)
		{
			$username = ucfirst($faker->word()) . ' ' . $faker->randomElement(static::ROLES);
			$key = strtolower($username);
			if (isset(self::$taken[$key]))
			{
				continue;
			}

			$probe = \XF::em()->create('XF:User');
			$probe->set('username', $username);
			if (!$probe->hasErrors())
			{
				self::$taken[$key] = true;

				return $username;
			}
		}

		throw new \RuntimeException('Could not find an account name that is not already taken after 50 attempts');
	}
}
