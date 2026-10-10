<?php namespace Hampel\ApiUsers\Service\ApiUser;

use Hampel\ApiUsers\Authentication\ApiOnly;
use XF\Entity\User;
use XF\Service\AbstractService;
use XF\Service\ValidateAndSavableTrait;

/**
 * Creates an API user: a valid account with no password, no email address and no public presence
 * as a member, recorded as an API user in this add-on's own table.
 *
 * The one place an API user is made, so that the command line and the control panel cannot
 * disagree about what one is.
 */
class Creator extends AbstractService
{
	use ValidateAndSavableTrait;

	/** @var User */
	protected $user;

	/** @var string */
	protected $note = '';

	/** @var int */
	protected $createdByUserId = 0;

	/** @var bool */
	protected $admin = false;

	public function __construct(\XF\App $app, $username)
	{
		parent::__construct($app);

		$this->user = $this->setupUser($username);
	}

	protected function setupUser($username)
	{
		/** @var User $user */
		$user = $this->repository('XF:User')->setupBaseUser();
		$user->setOption('admin_edit', true);

		$user->username = $username;
		$user->email = '';

		// Anything but valid and the REST API resolves the key's user to the guest permission
		// combination, which reads as a permissions fault and is not one.
		$user->user_state = 'valid';

		// Set on the account as well as enforced by the class extensions, because core honours
		// these with the add-on disabled and the extensions stop.
		$user->visible = false;
		$user->activity_visible = false;

		$user->Auth->scheme_class = ApiOnly::NAME;
		$user->Auth->data = [];

		$user->Privacy->bulkSet([
			'allow_view_profile' => 'none',
			'allow_post_profile' => 'none',
			'allow_send_personal_conversation' => 'none',
			'allow_view_identities' => 'none',
			'allow_receive_news_feed' => 'none',
		]);

		$user->Option->receive_admin_email = false;

		return $user;
	}

	public function setNote($note)
	{
		$this->note = (string) $note;
	}

	public function setCreatedBy(User $user)
	{
		$this->createdByUserId = $user->user_id;
	}

	/**
	 * Also make the account an administrator, holding no admin permissions.
	 *
	 * The record is written here and never through core's administrator controller, whose save
	 * demands the acting administrator's password - the caller's business, not this service's.
	 */
	public function setAdmin($admin)
	{
		$this->admin = (bool) $admin;
	}

	public function getUser()
	{
		return $this->user;
	}

	protected function _validate()
	{
		$this->user->preSave();
		$errors = $this->user->getErrors();

		if (mb_strlen($this->note) > 255)
		{
			$errors['note'] = \XF::phrase('hampel_apiusers_note_is_too_long');
		}

		return $errors;
	}

	protected function _save()
	{
		$user = $this->user;

		$db = $this->db();
		$db->beginTransaction();

		$user->save(true, false);

		$apiUser = $this->em()->create('Hampel\ApiUsers:ApiUser');
		$apiUser->bulkSet([
			'user_id' => $user->user_id,
			'created_date' => \XF::$time,
			'created_by_user_id' => $this->createdByUserId,
			'note' => $this->note,
		]);
		$apiUser->save(true, false);

		$user->hydrateRelation('ApiUser', $apiUser);

		if ($this->admin)
		{
			$admin = $this->em()->create('XF:Admin');
			$admin->bulkSet([
				'user_id' => $user->user_id,
				'permission_cache' => [],
				'is_super_admin' => false,
			]);
			$admin->save(true, false);
		}

		$db->commit();

		return $apiUser;
	}
}
