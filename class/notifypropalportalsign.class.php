<?php
/* Copyright (C) 2003-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2011 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2014	   Juanjo Menent		<jmenent@2byte.es>
 * Copyright (C) 2018 	   Philippe Grand		<philippe.grand@atoo-net.com>
 * Copyright (C) 2021 	   Thibault FOUCART		<support@ptibogxiv.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       notiforderdelivered/class/notiforderclose.class.php
 *      \ingroup    notiforderdelivered
 *      \brief      File of class to manage notifications
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/notify.class.php';

/**
 *      Class to manage notifications
 */
class NotifyPropalPortalSign extends Notify
{

	static public $arrayofnotifsupported = array('PROPAL_CLOSE_SIGNED_WEB');

	/**
	 *  Check if notification are active for couple action/company.
	 * 	If yes, send mail and save trace into llx_notify.
	 *
	 * 	@param	string	$notifcode			Code of action in llx_c_action_trigger (new usage) or Id of action in llx_c_action_trigger (old usage)
	 * 	@param	Object	$object				Object the notification deals on
	 *	@param 	array	$filename_list		List of files to attach (full path of filename on file system)
	 *	@param 	array	$mimetype_list		List of MIME type of attached files
	 *	@param 	array	$mimefilename_list	List of attached file name in message
	 *	@return	int							<0 if KO, or number of changes if OK
	 */
	public function send($notifcode, $object, $filename_list = array(), $mimetype_list = array(), $mimefilename_list = array())
	{
		global $user, $conf, $langs, $mysoc;
		global $hookmanager;
		global $dolibarr_main_url_root;
		global $action;

		if (!in_array($notifcode, $this::$arrayofnotifsupported)) {
			return 0;
		}

		include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('notification'));

		dol_syslog(get_class($this)."::send notifcode=".$notifcode.", object=".$object->id);

		$langs->loadLangs(array("other","notiforderdelivered@notiforderdelivered"));

		// Define $urlwithroot
		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
		//$urlwithroot=DOL_MAIN_URL_ROOT;						// This is to use same domain name than current
		if (!empty($conf->global->NOTIFICATION_NOTIFORDER_FORCE_IP)) {
			$urlwithroot = $conf->global->NOTIFICATION_NOTIFORDER_FORCE_IP;
		}
		// Define some vars
		$application = 'Dolibarr';
		if (!empty($conf->global->MAIN_APPLICATION_TITLE)) {
			$application = $conf->global->MAIN_APPLICATION_TITLE;
		}
		$replyto = $conf->notification->email_from;
		$object_type = '';
		$link = '';
		$num = 0;
		$error = 0;

		$oldref = (empty($object->oldref) ? $object->ref : $object->oldref);
		$newref = (empty($object->newref) ? $object->ref : $object->newref);

		// Check notification using fixed email
		if (!$error) {
			foreach ($conf->global as $key => $val) {
				$reg = array();
				if ($val == '' || !preg_match('/^NOTIFICATION_FIXEDEMAIL_'.$notifcode.'_THRESHOLD_HIGHER_(.*)$/', $key, $reg)) {
					continue;
				}

				$threshold = (float) $reg[1];
				if (!empty($object->total_ht) && $object->total_ht <= $threshold) {
					dol_syslog("A notification is requested for notifcode = ".$notifcode." but amount = ".$object->total_ht." so lower than threshold = ".$threshold.". We discard this notification");
					continue;
				}

				$param = 'NOTIFICATION_FIXEDEMAIL_'.$notifcode.'_THRESHOLD_HIGHER_'.$reg[1];

				$sendto = $conf->global->$param;
				$notifcodedefid = dol_getIdFromCode($this->db, $notifcode, 'c_action_trigger', 'code', 'rowid');
				if ($notifcodedefid <= 0) {
					dol_print_error($this->db, 'Failed to get id from code');
				}
				$trackid = '';

				$object_type = '';
				$link = '';
				$num++;

				$subject = '['.$mysoc->name.'] '.$langs->transnoentitiesnoconv("DolibarrNotification");

				switch ($notifcode) {
					case 'PROPAL_CLOSE_SIGNED_WEB':
						$link = '<a href="'.$urlwithroot.'/comm/propal/card.php?id='.$object->id.'&entity='.$object->entity.'">'.$newref.'</a>';
						$dir_output = $conf->propal->multidir_output[$object->entity]."/".get_exdir(0, 0, 0, 1, $object, 'propal');
						$object_type = 'propal';
						$labeltouse = $conf->global->PROPAL_CLOSE_SIGNED_TEMPLATE;
						$mesg = $langs->transnoentitiesnoconv("EMailTextProposalClosedSignedWeb", $link);
						break;
				}
				$ref = dol_sanitizeFileName($newref);
				$pdf_path = $dir_output."/".$ref."/".$ref.".pdf";
				if (!dol_is_file($pdf_path)) {
					// We can't add PDF as it is not generated yet.
					$filepdf = '';
				} else {
					$filepdf = $pdf_path;
					$filename_list[] = $pdf_path;
					$mimetype_list[] = mime_content_type($filepdf);
					$mimefilename_list[] = $ref.".pdf";
				}

				$message .= $langs->transnoentities("YouReceiveMailBecauseOfNotification2", $application, $mysoc->name)."\n";
				$message .= "\n";
				$message .= $mesg;

				$message = nl2br($message);

				// Replace keyword __SUPERVISOREMAIL__
				if (preg_match('/__SUPERVISOREMAIL__/', $sendto)) {
					$newval = '';
					if ($user->fk_user > 0) {
						$supervisoruser = new User($this->db);
						$supervisoruser->fetch($user->fk_user);
						if ($supervisoruser->email) {
							$newval = trim(dolGetFirstLastname($supervisoruser->firstname, $supervisoruser->lastname).' <'.$supervisoruser->email.'>');
						}
					}
					dol_syslog("Replace the __SUPERVISOREMAIL__ key into recipient email string with ".$newval);
					$sendto = preg_replace('/__SUPERVISOREMAIL__/', $newval, $sendto);
					$sendto = preg_replace('/,\s*,/', ',', $sendto); // in some case you can have $sendto like "email, __SUPERVISOREMAIL__ , otheremail" then you have "email,  , othermail" and it's not valid
					$sendto = preg_replace('/^[\s,]+/', '', $sendto); // Clean start of string
					$sendto = preg_replace('/[\s,]+$/', '', $sendto); // Clean end of string
				}

				if ($sendto) {
					$parameters = array('notifcode'=>$notifcode, 'sendto'=>$sendto, 'replyto'=>$replyto, 'file'=>$filename_list, 'mimefile'=>$mimetype_list, 'filename'=>$mimefilename_list);
					$reshook = $hookmanager->executeHooks('formatNotificationMessage', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
					if (empty($reshook)) {
						if (!empty($hookmanager->resArray['subject'])) {
							$subject .= $hookmanager->resArray['subject'];
						}
						if (!empty($hookmanager->resArray['message'])) {
							$message .= $hookmanager->resArray['message'];
						}
					}
					$mailfile = new CMailFile(
						$subject,
						$sendto,
						$replyto,
						$message,
						$filename_list,
						$mimetype_list,
						$mimefilename_list,
						'',
						'',
						0,
						1,
						'',
						$trackid,
						'',
						'',
						'notification'
					);

					if ($mailfile->sendfile()) {
						$sql = "INSERT INTO ".MAIN_DB_PREFIX."notify (daten, fk_action, fk_soc, fk_contact, type, type_target, objet_type, objet_id, email)";
						$sql .= " VALUES ('".$this->db->idate(dol_now())."', ".((int) $notifcodedefid).", ".($object->socid > 0 ? ((int) $object->socid) : 'null').", null, 'email', 'tofixedemail', '".$this->db->escape($object_type)."', ".((int) $object->id).", '".$this->db->escape($conf->global->$param)."')";
						if (!$this->db->query($sql)) {
							dol_print_error($this->db);
						}
					} else {
						$error++;
						$this->errors[] = $mailfile->error;
					}
				}
			}
		}

		if (!$error) {
			return $num;
		} else {
			return -1 * $error;
		}
	}
}
