<?php
/**
 * User Account behavior
 *
 * A configurable and portable user account management system  allowing account confirmation and password recovery
 *
 * PHP versions 4 and 5
 *
 * Copyright (c) 2008, Andy Dawson
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright	 Copyright (c) 2008, Andy Dawson
 * @link		  www.ad7six.com
 * @package	   base
 * @subpackage	base.models.behaviors
 * @since		 v 1.0
 * @license	   http://www.opensource.org/licenses/mit-license.php The MIT License
 */

/**
 * UserAccountBehavior class
 *
 * @uses		  ModelBehavior
 * @package	   base
 * @subpackage	base.models.behaviors
 */
class UserAccountBehavior extends ModelBehavior {

/**
 * name property
 *
 * @var string 'UserAccount'
 * @access public
 */
	var $name = 'UserAccount';

/**
 * defaultSettings property
 *
 * Fields:
 * 	current - the field for the user's current password
 * 	email - the field for the user's email
 * 	password - the same as the Auth component password field
 * 	password_confirm - a second password input to match the password
 * 	confirmation - used in the password recovery process. A field that the user must enter, defaults to username
 * 	username - the same as the Auth component username field
 * 	token - the field used for entering the (email) token. included here to reduce code repeition
 *
 * 	All Fields can be either "field", "associatedModel.field" or false.
 * Password Policies:
 * 	Define the rules for what is an acceptable password. salts as concatonated, that is the salt for 'strong'
 * 	includes all weaker policies
 * Password Policy:
 *	The current password policy
 * Token:
 * 	fields - which fields to be used when generating a token, defaults to all
 * 	length - how long to make the token, defaults to whatever Security::hash returns
 * 	recursive - the recursive value to be used when generating a token
 *
 * 	The fields and recursive settings allow a user's profile or address to be included in the token, whilst excluding
 * 	counterCache fields or other fields that may change often and are not useful to include in the token
 *
 * @var array
 * @access protected
 */
	var $_defaultSettings = array(
		'sendEmails' => array(
			'welcome' => true,
			'accountChange' => true,
			'tokenExpired' => true,
		),
		'fields' => array(
			'current' => 'current_password',
			'email' => 'email',
			'password' => 'password',
			'password_confirm' => 'confirm',
			'confirmation' => 'username',
			'username' => 'username',
			'token' => 'token',
			'tos' => 'tos'
		),
		'passwordPolicies' => array(
			'weak' => array('length' => 6, 'salt' => 'abcdefghijklmnopqrstuvwxyz0123456789'),
			'normal' => array('length' => 8),
			'medium' => array('length' => 8, 'salt' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
			'strong' => array('length' => 8, 'salt' => '!@#~$%&/()=+?"\',.;:-_*\/'),
			'super' => array('length' => 40)
		),
		'passwordPolicy' => 'medium',
		'token' => array(
			'fields' => '*',
			'length' => 0,
			'recursive' => -1
		)
	);

/**
 * setup method
 *
 * @param mixed $Model
 * @param array $config
 * @return void
 * @access public
 */
	function setup(&$Model, $config = array()) {
		$this->settings[$Model->alias] = Set::merge($this->_defaultSettings, $config);
	}

/**
 * accountFields method
 *
 * @param mixed $Model
 * @return void
 * @access public
 */
	function accountFields($Model) {
		return $this->settings[$Model->alias]['fields'];
	}

/**
 * If the user's password/email/username was changed, notify the user by email.
 *
 * If the user's password, email or username are changed notify the user by email
 *
 * @param mixed $created
 * @return void
 * @access public
 */
	function afterSave(&$Model, $created) {
		if ($created) {
			if ($this->settings[$Model->alias]['sendEmails']['welcome']) {
				$data[$Model->alias]['emailType'] = 'private';
				$data[$Model->alias]['token'] = $Model->token();
				$this->sendMail($Model, 'welcome', $data);
			}
			return;
		}
		if (!$this->settings[$Model->alias]['sendEmails']['accountChange']) {
			return;
		}
		extract($this->settings[$Model->alias]);
		if (!empty($__passwordChanged)) {
			$data[$Model->alias]['change'] = 'password';
			$data[$Model->alias]['emailType'] = 'private';
			$this->sendMail($Model, 'account_change', $data);
			unset ($this->settings[$Model->alias]['__passwordChanged']);
		}
		if (!empty($__emailChanged)) {
			$data[$Model->alias]['to'] = $__emailChanged;
			$data[$Model->alias]['change'] = 'email';
			$data[$Model->alias]['oldValue'] = $__emailChanged;
			$data[$Model->alias]['emailType'] = 'private';
			$this->sendMail($Model, 'account_change', $data);
			unset ($this->settings[$Model->alias]['__emailChanged']);
		}
		if (!empty($__usernameChanged)) {
			$data[$Model->alias]['change'] = 'username';
			$data[$Model->alias]['oldValue'] = $__usernameChanged;
			$data[$Model->alias]['emailType'] = 'private';
			$this->sendMail($Model, 'account_change', $data);
			unset($this->settings[$Model->alias]['__usernameChanged']);
		}
	}

/**
 * Flag an attempt to change password/email/username, to be able to notify the user later, if successful.
 *
 * @access public
 * @return void
 */
	function beforeSave(&$Model) {
		extract($this->settings[$Model->alias]['fields']);
		if ($Model->id) {
			if (isset($Model->data[$Model->alias][$password]) &&
				$Model->data[$Model->alias][$password] != $Model->field($password)) {
				$this->settings[$Model->alias]['__passwordChanged'] = true;
			}
			if (!empty($Model->data[$Model->alias][$email]) &&
				$Model->data[$Model->alias][$email] != $Model->field($email)) {
				$this->settings[$Model->alias]['__emailChanged'] = $Model->field($email);
			}
			if ($email != $username && isset($Model->data[$Model->alias][$username]) &&
				$Model->data[$Model->alias][$username] != $Model->field($username)) {
				$this->settings[$Model->alias]['__usernameChanged'] = $Model->field($username);
			}
		}
		return $Model->data;
	}

/**
 * beforeValidate method
 *
 * Setup validation rules.
 * If a generated password is requested, and strength is selected - only allow modification
 * if the user selects a strength stronger than the system default.
 *
 * @return void
 * @access public
 */
	function beforeValidate(&$Model) {
		$this->_setupValidation($Model);
		extract($this->settings[$Model->alias]);
		if (!empty($Model->data[$Model->alias]['generate'])) {
			unset($Model->data[$Model->alias]['generate']);
			$strengths = array_keys($passwordPolicies);
			$current = array_search($passwordPolicy, $strengths);
			if (isset($Model->data[$Model->alias]['strength'])) {
				$requested = array_search($Model->data[$Model->alias]['strength'], $strengths);
				if ($requested > $current) {
					$this->settings[$Model->alias]['passwordPolicy'] =
						$Model->data[$Model->alias]['strength'];
				}
			}
			$this->settings[$Model->alias]['tempPassword'] =
				$Model->data[$Model->alias][$fields['password_confirm']] = $this->generatePassword($Model);
			$Model->data[$Model->alias][$fields['password']] =
				Security::hash($Model->data[$Model->alias][$fields['password_confirm']], null, true);
		}
		return true;
	}

/**
 * Process a password change request.
 *
 * This process is used when a logged in user tries to change his password.
 *
 * @param mixed $Model
 * @param mixed $data
 * @param array $loggedInUser
 * @return array(bool, string) success/fail and an optional message
 * @access public
 */
	function changePassword(&$Model, $data, $loggedInUser = array()) {
		if (isset($loggedInUser[$Model->alias])) {
			$loggedInUser = $loggedInUser[$Model->alias];
		}
		extract($this->settings[$Model->alias]);
		$data[$Model->alias][$Model->primaryKey] = $loggedInUser[$Model->primaryKey];
		if (!empty($loggedInUser[$fields['username']])) {
			$data[$Model->alias][$fields['username']] = $loggedInUser[$fields['username']];
		} else {
			$data[$Model->alias][$fields['username']] = $Model->field($fields['username']);
		}
		if ($data = $Model->save($data, true, array($fields['current'], $fields['password'], $fields['password_confirm']))) {
			$message = __d('mi_users', 'Your password has been changed', true);
			if (!empty($this->settings[$Model->alias]['tempPassword'])) {
				$message .= sprintf(__d('mi_users', '. Your new password is <strong>%1$s</strong>', true), $this->settings[$Model->alias]['tempPassword']);
				unset($this->settings[$Model->alias]['tempPassword']);
			}
			return array(true, $message);
		} else {
			$message = __d('mi_users', 'There was a problem changing your password', true);
		}
		return array(false, $message);
	}

/**
 * Process a user account confirmation request.
 *
 * This process forms the first part of a password reset, in that case $password is True.
 *
 * @param mixed $Model
 * @param array $data
 * @param bool $force
 * @param bool $password
 * @return array(bool, string) success/fail and an optional message
 * @access public
 */
	function confirmAccount(&$Model, $data = array(), $force = false, $password = false) {
		extract($this->settings[$Model->alias]);
		if (!$force) {
			$missing = false;
			foreach (array('email', 'confirmation', 'token') as $field) {
				if ($fields[$field] === false) {
					continue;
				}
				$field = $fields[$field];
				$alias = $Model->alias;
				if (strpos('.', $field)) {
					list($alias, $field) = explode($field, '.');
				}
				if (!isset($data[$alias][$field])) {
					$missing = true;
					if ($alias == $Model->alias) {
						$Model->invalidate($field, 'missing');
					} else {
						$Model->$alias->invalidate($field, 'missing');
					}
				}
			}
			if ($missing) {
				return array(false, null);
			}
			$conditions = array($Model->alias . '.' . $fields['email'] => $data[$Model->alias][$fields['email']]);
			$fields = '*';
			$recursive = 0;
			$user = $Model->find('first', compact('fields', 'recursive', 'conditions'));
			$fields = $this->settings[$Model->alias]['fields'];
			if (!$user) {
				$Model->invalidate('token', 'not found');
				$message = __d('mi_users', 'token not found', true);
				if (Configure::read()) {
					$message .= ' <br />DEBUG:' . __d('mi_users', 'email not found', true);
				}
				return array(false, $message);
			}
			$Model->id = $user[$Model->alias][$Model->primaryKey];
			if ($fields['confirmation'] !== false && !$Model->userAccountField($fields['confirmation'], $data, true)) {
				$Model->invalidate('token', 'not found');
				$message = __d('mi_users', 'token not found', true);
				if (Configure::read()) {
					$message .= ' <br />DEBUG:' . sprintf(__d('mi_users', '%1$s does not match for email %2$s', true),
						$fields['confirmation'], $data[$Model->alias][$fields['email']]);
				}
				return array(false, $message);
			}
			$token = $this->token($Model, $user);
			if ($token !== $data[$Model->alias]['token']) {
				$Model->invalidate('token', 'not found');
				$message = __d('mi_users', 'token not found', true);
				if (Configure::read()) {
					$message .= ' <br />DEBUG:' . __d('mi_users', 'token does not match', true);
					$message .= ' <br />' . $token;
				}
				return array(false, $message);
			}
			$expires = strtotime($user[$Model->alias]['modified']) + 60 * 60 * 24;
			if ($expires < time() && !$force) {
				$Model->invalidate('token', 'expired');
				if ($sendEmails['tokenExpired']) {
					if ($password) {
						$this->sendMail($Model, 'new_password');
						$message = __d('mi_users', 'email token expired', true);
					} else {
						$this->sendMail($Model, 'new_token');
						$message = __d('mi_users', 'confirm email token expired', true);
					}
				}
				return array(false, $message);
			}
		}
		if ($password) {
			return true;
		}
		$message = '';
		$Model->id = $user[$Model->alias][$Model->primaryKey];
		if ($return = $Model->saveField('email_verified', true)) {
			$message = __d('mi_users', 'Thank you for confirming your account', true);
			return array(true, $message);
		}
		return array(false, $message);
	}

/**
 * Try to get a display value for a user.
 *
 * Fall back. Assumes that find list is setup such that it returns users real names.
 * If $Model->displayField is set to primary key (default or not manually set), it
 * defaults to user's username.
 *
 * @param mixed $id
 * @return string
 * @access public
 */
	function display(&$Model, $id = null) {
		if (!$id) {
			if (!$Model->id) {
				return false;
			}
			$id = $Model->id;
		}
		if ($Model->displayField == $Model->primaryKey) {
			$Model->displayField = $this->settings[$Model->alias]['fields']['username'];
		}
		return current($Model->find('list', array('conditions' => array($Model->alias . '.' . $Model->primaryKey => $id))));
	}

/**
 * Process a forgotten password request.
 *
 * If Model has been modified within the last 24hs, the last valid token is sent.
 * Otherwise, a new token will be generated and the last one will no longer be valid.
 *
 * Process a forgotten password request
 *
 * @param mixed $Model
 * @param array $request
 * @return array(bool, string) success/fail and an optional message
 * @access public
 */
	function forgottenPassword(&$Model, $request = '') {
		$request = trim($request);
		extract($this->settings[$Model->alias]);
		$Model->recursive = 0;
		if ($fields['username'] == $fields['email']) {
			$conditions = array($Model->alias . '.' . $fields['email'] => $request);
		} else {
			$conditions = array('OR' => array(
				$Model->alias . '.' . $fields['email'] => $request,
				$Model->alias . '.' . $fields['username'] => $request
			));
		}
		$id = $Model->field($Model->primaryKey, $conditions);
		$message = __d('mi_users', 'There was a problem requesting a password reset', true);
		if ($id) {
			$Model->id = $id;
			//$Model->log($request, 'forgotten_password_valid');
			$expires = strtotime($Model->field('modified')) + 60 * 60 * 24;
			if ($expires < time()) {
				$Model->save(array(), false);
			}
			$data[$Model->alias]['token'] = $Model->token();
			$data[$Model->alias]['emailType'] = 'private';
			if ($this->sendMail($Model, 'forgotten_password', $data)) {
				$message = __d('mi_users', 'password change email sent', true);
				return array(true, $message);
			}
			$message = __d('mi_users', 'There was a problem sending an email', true);
		} else {
			//$Model->log($request, 'forgotten_password_invalid');
			$message = __d('mi_users', 'A password reset has been requested, an email has been sent', true);
			if (Configure::read()) {
				$message .= ' <br />DEBUG:' . __d('mi_users', 'email not found', true);
			}
			return array(true, $message);
		}
		return array(false, $message);
	}

/**
 * Generate a random password according to current password policies.
 *
 * The length of the password is the maximum of the requested length or the length
 * specified by the current password policy.
 *
 * @param mixed $Model
 * @param int $length
 * @return string
 * @access public
 */
	function generatePassword(&$Model, $length = 6) {
		extract($this->settings[$Model->alias]);
		$salt = '';
		foreach ($passwordPolicies as $name => $policy) {
			if (isset($policy['salt'])) {
				$salt .= $policy['salt'];
			}
			if (isset($policy['length'])) {
				$length = max($length, $policy['length']);
			}
			if ($name == $passwordPolicy) {
				break;
			}
		}
		$_id = $Model->id;
		$_data = $Model->data;
		do {
			$Model->create();
			$password = $this->__generateRandom($length, $salt);
			$Model->data[$Model->alias][$fields['password']] = Security::hash($password, null, true);
			$Model->data[$Model->alias][$fields['password_confirm']] = $password;
		} while (!$Model->validates());
		$Model->create();
		$Model->id = $_id;
		$Model->data = $_data;
		return $password;
	}

/**
 * passwordPolicies method
 *
 * Returns all password policies
 *
 * @param mixed $Model
 * @return array
 * @access public
 */
	function passwordPolicies(&$Model) {
		return $this->settings[$Model->alias]['passwordPolicies'];
	}

/**
 * passwordPolicy method
 *
 * Returns the current password policy
 *
 * @param mixed $Model
 * @return string
 * @access public
 */
	function passwordPolicy(&$Model) {
		return $this->settings[$Model->alias]['passwordPolicy'];
	}

/**
 * register method
 *
 * Process a user registration request
 *
 * @param mixed $Model
 * @param mixed $data
 * @return array(bool, string) success/fail and an optional message
 * @access public
 */
	function register(&$Model, $data, $whitelist = array()) {
		extract($this->settings[$Model->alias]);
		if ($_data = $Model->saveAll($data, array('validate' => true, 'fieldList' => $whitelist))) {
			$message = sprintf(__d('mi_users', 'Welcome %1$s!', true), $Model->display());
			if (!empty($data[$Model->alias]['generate'])) {
				$message .= sprintf(__d('mi_users', '. Your password is <strong>%1$s</strong>', true),
					$_data[$Model->alias][$fields['password_confirm']]);
			}
			return array(true, $message);
		} else {
			$message = __d('mi_users', 'errors in form', true);
		}
		return array(false, $message);
	}

/**
 * Process a password reset request.
 *
 * If force is true, it is not necessary to enter the current/previous password
 *
 * @param mixed $Model
 * @param array $data
 * @param bool $force
 * @return array(bool, string) success/fail and an optional message
 * @access public
 */
	function resetPassword(&$Model, $data = array(), $force = false) {
		extract($this->settings[$Model->alias]);
		$return = $this->confirmAccount($Model, $data, $force, true);
		if ($return !== true) {
			return $return;
		}
		if (!$force) {
			if (!isset($data[$Model->alias][$fields['password']])) {
				$message = __d('mi_users', 'Please enter your new password', true);
				return array(false, $message);
			}
		}
		$message = '';
		$Model->id = $Model->field($Model->primaryKey, array($fields['username'] =>
			$data[$Model->alias][$fields['username']]));
		$data[$Model->alias]['email_verified'] = 1;
		if ($return = $Model->save($data, true, array($fields['password'], $fields['password_confirm'], 'email_verified'))) {
			$message = __d('mi_users', 'Your password has been changed. Please login', true);
			if (!empty($this->settings[$Model->alias]['tempPassword'])) {
				$message .= sprintf(__d('mi_users', '. Your new password is <strong>%1$s</strong>', true), $this->settings[$Model->alias]['tempPassword']);
				unset($this->settings[$Model->alias]['tempPassword']);
			}
			return array(true, $message);
		}
		return array(false, $message);
	}

/**
 * Send the user an email.
 *
 * Relies upon the existance of the (customizable) MiEmail class in the application.
 *
 * @param mixed $Model
 * @param mixed $template
 * @param array $data
 * @param mixed $subject
 * @return bool
 * @access public
 */
	function sendMail(&$Model, $template, $data = array(), $subject = null) {
		if (!$Model->id) {
			if (empty($data[$Model->primaryKey])) {
				return false;
			}
			$Model->id = $data[$Model->primaryKey];
		}
		if (!strpos($template, '/')) {
			$template = Inflector::tableize($Model->alias) . '/' . $template;
		}
		$fields = $this->settings[$Model->alias]['fields'];
		$defaultData = $Model->find('first', array('conditions' => array($Model->alias . '.' . $Model->primaryKey => $Model->id)));
		$defaultData[$Model->alias]['to'] = $defaultData[$Model->alias][$fields['email']];
		$defaultData[$Model->alias]['from_user_id'] = 0;
		$defaultData[$Model->alias]['emailType'] = 'normal';
		$data = Set::merge($defaultData, $data);
		$to = $data[$Model->alias]['to'];
		$emailType = $data[$Model->alias]['emailType'];
		$MiEmail = ClassRegistry::init('MiEmail');
		$MiEmail->create();
		$MiEmail->send(array(
			'from_user_id' => $data[$Model->alias]['from_user_id'],
			'to_user_id' => $Model->id,
			'to' => $Model->display() . " <{$to}>",
			'template' => $template,
			'data' => $data,
			'subject' => $subject,
			'type' => $emailType
		));
		return true;
	}

/**
 * Returns a token derived from the user record data.
 *
 * If data is not passed, it is taken from the user record, using the settings defined for token.
 *
 * @param mixed $Model
 * @param array $data
 * @param mixed $params
 * @return string
 * @access public
 */
	function token(&$Model, $data = array(), $params = array()) {
		extract($this->settings[$Model->alias]['token']);
		extract($params);
		if ($data) {
			if ($fields === '*') {
				$fields = array_keys($Model->schema());
			}
			$data = array($Model->alias => array_intersect_key($data[$Model->alias], array_flip($fields)));
			if (count($data[$Model->alias]) !== count($fields) && $Model->id) {
				$data = array();
			}
		}
		if (!$data) {
			$conditions = array($Model->primaryKey => $Model->id);
			$data = $Model->find('first', compact('conditions', 'fields', 'recursive'));
		}
		$return = Security::hash(serialize($data), null, true);
		if ($length) {
			while (strlen($return) < $length) {
				$return .= Security::hash($return, null, true);
			}
			$return = substr($return, 0, $length);
		}
		return $return;
	}

/**
 * userAccountField method
 *
 * Override this method in the user mode if more complex logic is required
 *
 * @param mixed $Model
 * @param string $field
 * @return void
 * @access public
 */
	function userAccountField(&$Model, $field = 'username', $data) {
		$field = $this->settings[$Model->alias]['fields'][$field];
		$alias = $Model->alias;
		if (strpos('.', $field)) {
			list($alias, $field) = explode($field, '.');
		}
		if (!isset($data[$alias][$field])) {
			$missing = true;
			if ($alias == $Model->alias) {
				$Model->invalidate($field, 'missing');
			} else {
				$Model->$alias->invalidate($field, 'missing');
			}
		}
		return $Model->field($field);
	}

/**
 * Does the password confirm match the password?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validateConfirmMatch(&$Model, $data, $compare) {
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$compare[0]];
		return (Security::hash($value, null, true) === $compare);
	}

/**
 * Is the current password entered value the same as the user's password?
 *
 * @param mixed $Model
 * @param mixed $data
 * @return bool
 * @access public
 */
	function validateCurrentPassword(&$Model, $data) {
		$key = key($data);
		$value = $data[$key];
		if (!$value) {
			return false;
		}
		extract($this->settings[$Model->alias]);
		return (Security::hash($value, null, true) === $Model->field($fields['password']));
	}

/**
 * Does the new password contain at least one UPPER and one lower case letter?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validatePasswordCase(&$Model, $data, $compare) {
		if (in_array($this->settings[$Model->alias]['passwordPolicy'], array('weak', 'normal'))) {
			return true;
		}
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$compare[0]];
		if (!(preg_match('/.*[a-z].*/', $compare))) {
			return false;
		}
		if (!(preg_match('/.*[A-Z].*/', $compare))) {
			return false;
		}
		return true;
	}

/**
 * Is the new password different from the old one?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $params
 * @return bool
 * @access public
 */
	function validatePasswordDifferent(&$Model, $data, $compare) {
		if (!isset($Model->data[$Model->alias][$compare[1]])) {
			return true;
		}
		$key = key($data);
		$value = $data[$key];
		$password = $Model->data[$Model->alias][$compare[0]];
		$current = $Model->data[$Model->alias][$compare[1]];
		return ($password != $current);
	}

/**
 * Is the new password at least as long as the current password policy minimum?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validatePasswordLength(&$Model, $data, $compare) {
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$compare[0]];
		$length = strlen($compare);
		if ($length < $this->settings[$Model->alias]['passwordPolicies'][$this->settings[$Model->alias]['passwordPolicy']]['length']) {
			return false;
		}
		return true;
	}

/**
 * Is the new password empty?
 *
 * An empty password can be one full of whitespaces (spaces, tabs or line breaks).
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validatePasswordNotEmpty(&$Model, $data, $compare) {
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$compare[0]];
		return (preg_match('/[^\\s]/', $compare));
	}

/**
 * Does the new password contain one number, but isn't all numbers?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validatePasswordNumber(&$Model, $data, $compare) {
		if ($this->settings[$Model->alias]['passwordPolicy'] == 'weak') {
			return true;
		}
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$compare[0]];
		if (!(preg_match('/.*\d.*/', $compare))) {
			return false;
		}
		if (!(preg_match('/.*[a-zA-Z].*/i', $compare))) {
			return false;
		}
		return true;
	}

/**
 * Does the new password contain at least one special (not letters, digits or underscores) character?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validatePasswordSpecialChar(&$Model, $data, $compare) {
		if (in_array($this->settings[$Model->alias]['passwordPolicy'], array('weak', 'normal', 'medium'))) {
			return true;
		}
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$compare[0]];
		if (!(preg_match('/.*[^\w].*/', $compare))) {
			return false;
		}
		return true;
	}

/**
 * Does the new password contain the username?
 *
 * @param mixed $Model
 * @param mixed $data
 * @param mixed $compare
 * @return bool
 * @access public
 */
	function validatePasswordUsername(&$Model, $data, $params) {
		if ($this->settings[$Model->alias]['passwordPolicy'] == 'weak' || !isset($Model->data[$Model->alias][$params[1]])) {
			return true;
		}
		$key = key($data);
		$value = $data[$key];
		$compare = $Model->data[$Model->alias][$params[0]];
		$username = $Model->data[$Model->alias][$params[1]];
		if (strpos($compare, $username) !== false) {
			return false;
		}
		return true;
	}

/**
 * Returns a random string of the requested length (salting the process).
 *
 * @param int $length
 * @param string $salt
 * @return string
 * @access private
 */
	function __generateRandom($length = 8, $salt = 'abcdefghijklmnopqrstuvwxyz0123456789') {
		$salt = str_shuffle($salt);
		$return = "";
		$i = 0;
		while ($i < $length) {
			$num = rand(0, strlen($salt) -1);
			$tmp = $salt[$num];
			$return .= $tmp;
			$i++;
		}
		return str_shuffle($return);
	}

/**
 * Add validation rules specific to this behavior.
 *
 * Prepend the behavior's validation rules. Set::merge() should be used inside
 * $Model to correctly set $Model->validate
 * To allow the behavior to modify the model's data for any other validation rules.
 *
 * @param mixed $Model
 * @return void
 * @access protected
 */
	function _setupValidation(&$Model) {
		extract ($this->settings[$Model->alias]['fields']);
		$Model->validate[$password_confirm] = array('notSame' => array('rule' => 'validateConfirmMatch',
			$password));
		$Model->validate[$current] = array('notCurrent' => array('rule' => 'validateCurrentPassword'));
		$Model->validate[$password] = array(
			'missing' => array('rule' => 'validatePasswordNotEmpty', $password_confirm, 'last' => true),
			'tooShort' => array('rule' => 'validatePasswordLength', $password_confirm, 'last' => true),
			'containsUsername' => array('rule' => 'validatePasswordUsername', 'confirm', $username, 'last' => true),
			'number' => array('rule' => 'validatePasswordNumber', $password_confirm, 'last' => true),
			'case' => array('rule' => 'validatePasswordCase', $password_confirm, 'last' => true),
			'special' => array('rule' => 'validatePasswordSpecialChar', $password_confirm, 'last' => true),
			'notChanged' => array('rule' => 'validatePasswordDifferent', $password_confirm, $current, 'last' => true),
		);
		$Model->validate[$tos][] = array('rule' => array('equalTo', '1'));
	}
}
?>
