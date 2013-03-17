<?php
/**
 * ownCloud - Mail app
 *
 * @author Sebastian Schmid
 * @copyright 2013 Sebastian Schmid mail@sebastian-schmid.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Mail\Controller;

use OCA\AppFramework\Controller\Controller;
use OCA\AppFramework\Db\DoesNotExistException;

use OCA\Mail\Db\MailAccount;

use OCA\Mail\Account;

class MailAccountController extends Controller {

	/**
	 * @var \OCA\Mail\Db\MailAccountMapper
	 */
	private $mailAccountMapper;

	/**
	 * @param \OCA\AppFramework\Http\Request $request: an instance of the request
	 * @param \OCA\AppFramework\Core\API $api: an api wrapper instance
	 * @param \OCA\Mail\Db\MailAccountMapper $mailAccountMapper: a mail account wrapper instance
	 */
	public function __construct($api, $request, $mailAccountMapper){
		parent::__construct($api, $request);
		$this->mailAccountMapper = $mailAccountMapper;
	}

	/**
	 * @CSRFExemption
	 * @IsAdminExemption
	 * @IsSubAdminExemption
	 *
	 * @brief renders the index page
	 * @return an instance of a Response implementation
	 */
	public function index() {
		$accounts = array();
		try {
			$accounts = $this->mailAccountMapper->findByUserId($this->api->getUserId());
			$templateName = 'index';
			$params = $accounts;
		} catch (DoesNotExistException $e) {
			$templateName = 'part.no-accounts';
			$params = array(
				'accounts' => $accounts
			);
		}

		return $this->render($templateName, $params);
	}

	/**
	 * @IsAdminExemption
	 * @IsSubAdminExemption
	 *
	 * @return \OCA\AppFramework\Http\TemplateResponse
	 */
	public function create() {
		$ocUserId = $this->api->getUserId();
		$email = $this->params('mail-address');
		$password = $this->params('mail-password');

		// splitting the email address into user and host part
		list($user, $host) = explode("@", $this->params('mail-address'));

		/**
		 * Google Apps
		 * used if $host points to Google Apps
		 */
		if ($this->isGoogleAppsAccount($host)) {
			$isNewAccount = $this->testAccount($user, $email, "imap.gmail.com", $email, $password);
		} else {
			/*
			 * IMAP login with full email address as user
			 * works for a lot of providers (e.g. Google Mail)
			 */
			$isNewAccount = $this->testAccount($user, $email, $host, $email, $password);
		}
		if($isNewAccount) {
			$templateName = 'index';
			$params = array();
		} else {
			$templateName = 'part.no-accounts';
			$params = array(
				'url_create' => $this->api->linkToRoute('mail_mailaccount_create')
			);
			return $this->render($templateName, $params);
		}

		return $this->render($templateName, $params);
	}

	/**
	 * check if the host is Google Apps
	 */
	private function isGoogleAppsAccount($host) {
		// filter pure gmail accounts
		if (stripos($host, 'google') !== false) {
			return false;
		}
		if (stripos($host, 'gmail') !== false) {
			return false;
		}

		//
		// TODO: will not work on windows - ignore this for now
		//
		if (getmxrr($host, $mx_records, $mx_weight) == false) {
			return false;
		}

		if (stripos($mx_records[0], 'google') !== false) {
			return true;
		}
		return false;
	}

	/**
	 * try to log into the mail account using different ports
	 * and use SSL if available
	 * IMAP - port 143
	 * Secure IMAP (IMAP4-SSL) - port 585
	 * IMAP4 over SSL (IMAPS) - port 993
	 */
	private function testAccount($user_id, $email, $host, $user, $password) {
		$account = array(
			'name'     => $email,
			'host'     => $host,
			'user'     => $user,
			'password' => $password,
		);

		$ports = array(143, 585, 993);
		$sec_modes = array('ssl', 'tls', null);
		$host_prefixes = array('', 'imap.');
		foreach ($host_prefixes as $host_prefix) {
			$h = $host_prefix . $host;
			$account['host'] = $h;
			foreach ($ports as $port) {
				$account['port'] = $port;
				foreach ($sec_modes as $sec_mode) {
					$account['ssl_mode'] = $sec_mode;
					try {
						$testAccount = new Account();
						$testAccount->getImapConnection($h, $port, $user, $password, $sec_mode);
						$this->api->log("Test-Account-Successful: $user_id, $h, $port, $user, $sec_mode");
						return $this->addAccount($user_id, $email, $h, $port, $user, $password, $sec_mode);
					} catch (\Horde_Imap_Client_Exception $e) {
						$this->api->log("Test-Account-Failed: $user_id, $h, $port, $user, $sec_mode");
					}
				}
			}
		}
		return false;
	}

	/**
	 * @param string $host
	 * @param int $port
	 * @param string $user
	 * @param string $password
	 * @param string $ssl_mode
	 * @return \Horde_Imap_Client_Socket a ready to use IMAP connection
	 */
	private function getImapConnection($host, $port, $user, $password, $ssl_mode) {
		$imapConnection = new \Horde_Imap_Client_Socket(array(
			'username' => $user, 'password' => $password, 'hostspec' => $host, 'port' => $port, 'secure' => $ssl_mode, 'timeout' => 2));
		$imapConnection->login();
		return $imapConnection;
	}

	/**
	 * Saves the mail account credentials for a users mail account
	 *
	 * @IsAdminExemption
	 * @IsSubAdminExemption
	 *
	 * @param $ocUserId
	 * @param $email
	 * @param $inboundHost
	 * @param $inboundHostPort
	 * @param $inboundUser
	 * @param $inboundPassword
	 * @param $inboundSslMode
	 * @return int MailAccountId
	 */
	private function addAccount($ocUserId, $email, $inboundHost, $inboundHostPort, $inboundUser, $inboundPassword, $inboundSslMode) {

		$mailAccount = new MailAccount();
		$mailAccount->setOcUserId($ocUserId);
		$mailAccount->setMailAccountId(time());
		$mailAccount->setMailAccountName($email);
		$mailAccount->setEmail($email);
		$mailAccount->setInboundHost($inboundHost);
		$mailAccount->setInboundHostPort($inboundHostPort);
		$mailAccount->setInboundSslMode($inboundSslMode);
		$mailAccount->setInboundUser($inboundUser);
		$mailAccount->setInboundPassword($inboundPassword);

		$this->mailAccountMapper->save($mailAccount);

		return $mailAccount->getMailAccountId();
	}

}