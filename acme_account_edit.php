<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2026
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Norman King <norman@nsinnovations.net>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!(permission_exists('acme_account_add') || permission_exists('acme_account_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$acme_account_name = '';
	$acme_account_directory = acme_accounts::presets()['option-letsencrypt'];
	$acme_account_email = '';
	$acme_account_eab_kid = '';
	$acme_account_eab_hmac = '';
	$acme_account_url = '';
	$acme_account_status = '';
	$acme_account_enabled = 'true';
	$acme_account_description = '';
	$eab_hmac_saved = false;

//action add or update
	if (is_uuid($_REQUEST["id"] ?? '')) {
		$action = "update";
		$acme_account_uuid = $_REQUEST["id"];
	}
	else {
		$action = "add";
	}

//get posted data
	if (count($_POST) > 0) {
		$acme_account_name = $_POST['acme_account_name'] ?? '';
		$acme_account_directory_preset = $_POST['acme_account_directory_preset'] ?? '';
		$acme_account_directory = $acme_account_directory_preset !== '' ? $acme_account_directory_preset : trim($_POST['acme_account_directory'] ?? '');
		$acme_account_email = trim($_POST['acme_account_email'] ?? '');
		$acme_account_eab_kid = trim($_POST['acme_account_eab_kid'] ?? '');
		$acme_account_eab_hmac = trim($_POST['acme_account_eab_hmac'] ?? '');
		$acme_account_enabled = $_POST['acme_account_enabled'] ?? 'false';
		$acme_account_description = $_POST['acme_account_description'] ?? '';
	}

//process the http post data
	if (count($_POST) > 0 && empty($_POST["persistformvar"])) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: acme_accounts.php');
				exit;
			}

		//get the saved account, to keep what the form does not change
			$saved = [];
			if ($action == 'update') {
				$sql = "select acme_account_directory, acme_account_eab_hmac, acme_account_key, acme_account_url ";
				$sql .= "from v_acme_accounts ";
				$sql .= "where acme_account_uuid = :acme_account_uuid ";
				$parameters['acme_account_uuid'] = $acme_account_uuid;
				$saved = $database->select($sql, $parameters, 'row') ?: [];
				unset($sql, $parameters);
			}

		//check for all required data
			$msg = '';
			if (empty($acme_account_name)) { $msg .= $text['message-required']." ".$text['label-name']."<br>\n"; }
			if (!preg_match('#^https://[^\s/]+(/\S*)?$#', $acme_account_directory)) { $msg .= $text['message-acme_account_directory_invalid']."<br>\n"; }
			if (!empty($acme_account_email) && !valid_email($acme_account_email)) { $msg .= $text['message-acme_account_email_invalid']."<br>\n"; }
			if (!empty($acme_account_eab_hmac) && empty($acme_account_eab_kid)) { $msg .= $text['message-required']." ".$text['label-acme_account_eab_kid']."<br>\n"; }
			if (!empty($msg) && empty($_POST["persistformvar"])) {
				require_once "resources/header.php";
				require_once "resources/persist_form_var.php";
				echo "<div align='center'>\n";
				echo "<table><tr><td>\n";
				echo $msg."<br />";
				echo "</td></tr></table>\n";
				persistformvar($_POST);
				echo "</div>\n";
				require_once "resources/footer.php";
				return;
			}

		//build the array
			if ($action == "add" && permission_exists('acme_account_add')) {
				$acme_account_uuid = uuid();
			}
			$array['acme_accounts'][0]['acme_account_uuid'] = $acme_account_uuid;
			$array['acme_accounts'][0]['acme_account_name'] = $acme_account_name;
			$array['acme_accounts'][0]['acme_account_directory'] = $acme_account_directory;
			$array['acme_accounts'][0]['acme_account_email'] = $acme_account_email;
			$array['acme_accounts'][0]['acme_account_eab_kid'] = $acme_account_eab_kid;
			$array['acme_accounts'][0]['acme_account_enabled'] = $acme_account_enabled;
			$array['acme_accounts'][0]['acme_account_description'] = $acme_account_description;

		//a blank hmac key keeps the saved one
			if (!empty($acme_account_eab_hmac)) {
				$crypto = new cert_manager_crypto(['settings' => $settings]);
				try {
					$array['acme_accounts'][0]['acme_account_eab_hmac'] = $crypto->encrypt($acme_account_eab_hmac);
				}
				catch (Exception $e) {
					message::add($text['message-key_file_missing'], 'negative');
					header('Location: acme_accounts.php');
					exit;
				}
			}

		//an account registered with another certificate authority has to register again
			if (!empty($saved['acme_account_directory']) && $saved['acme_account_directory'] !== $acme_account_directory) {
				$array['acme_accounts'][0]['acme_account_key'] = null;
				$array['acme_accounts'][0]['acme_account_url'] = null;
				$array['acme_accounts'][0]['acme_account_status'] = null;
			}

		//save the data
			$database->save($array);
			unset($array);

		//set the message
			if ($action == "add") {
				message::add($text['message-add']);
			}
			if ($action == "update") {
				message::add($text['message-update']);
			}

		//redirect the browser
			header("Location: acme_account_edit.php?id=".urlencode($acme_account_uuid));
			exit;
	}

//pre-populate the form
	if (!empty($acme_account_uuid) && empty($_POST["persistformvar"])) {
		$sql = "select acme_account_name, acme_account_directory, acme_account_email, acme_account_eab_kid, ";
		$sql .= "acme_account_eab_hmac, acme_account_url, acme_account_status, ";
		$sql .= "cast(acme_account_enabled as text) as acme_account_enabled, acme_account_description ";
		$sql .= "from v_acme_accounts ";
		$sql .= "where acme_account_uuid = :acme_account_uuid ";
		$parameters['acme_account_uuid'] = $acme_account_uuid;
		$row = $database->select($sql, $parameters, 'row');
		if (is_array($row) && @sizeof($row) != 0) {
			$acme_account_name = $row["acme_account_name"];
			$acme_account_directory = $row["acme_account_directory"];
			$acme_account_email = $row["acme_account_email"];
			$acme_account_eab_kid = $row["acme_account_eab_kid"];
			$eab_hmac_saved = !empty($row["acme_account_eab_hmac"]);
			$acme_account_url = $row["acme_account_url"];
			$acme_account_status = $row["acme_account_status"];
			$acme_account_enabled = $row["acme_account_enabled"];
			$acme_account_description = $row["acme_account_description"];
		}
		unset($sql, $parameters, $row);
	}

//find the preset of the directory
	$acme_account_directory_preset = in_array($acme_account_directory, acme_accounts::presets(), true) ? $acme_account_directory : '';

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-acme_account'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-acme_account']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'acme_accounts.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-acme_account']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-name']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='acme_account_name' maxlength='255' value=\"".escape($acme_account_name)."\" required='required'>\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-acme_account_directory']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='acme_account_directory_preset' id='acme_account_directory_preset' onchange=\"document.getElementById('acme_account_directory').style.display = this.value == '' ? '' : 'none';\">\n";
	foreach (acme_accounts::presets() as $key => $url) {
		echo "		<option value='".escape($url)."' ".($url == $acme_account_directory_preset ? "selected='selected'" : null).">".$text[$key]."</option>\n";
	}
	echo "		<option value='' ".($acme_account_directory_preset == '' ? "selected='selected'" : null).">".$text['option-custom']."</option>\n";
	echo "	</select>\n";
	echo "	<input class='formfld' type='url' name='acme_account_directory' id='acme_account_directory' maxlength='255' placeholder='https://' value=\"".escape($acme_account_directory_preset == '' ? $acme_account_directory : '')."\" style='".($acme_account_directory_preset == '' ? '' : 'display: none;')."'>\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_directory']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-email']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='email' name='acme_account_email' maxlength='255' value=\"".escape($acme_account_email)."\">\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_email']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-acme_account_eab_kid']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='acme_account_eab_kid' maxlength='255' value=\"".escape($acme_account_eab_kid)."\">\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_eab_kid']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-acme_account_eab_hmac']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='password' name='acme_account_eab_hmac' maxlength='255' autocomplete='new-password' value='' placeholder=\"".($eab_hmac_saved ? $text['label-unchanged'] : '')."\">\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_eab_hmac']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if ($action == 'update') {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-status']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	".escape(!empty($acme_account_status) ? ($text['option-status_'.$acme_account_status] ?? $acme_account_status) : $text['option-status_unregistered'])."\n";
		if (!empty($acme_account_url)) {
			echo "	<br />\n";
			echo "	".escape($acme_account_url)."\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='acme_account_enabled'>\n";
	echo "		<option value='true' ".($acme_account_enabled != 'false' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "		<option value='false' ".($acme_account_enabled == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='acme_account_description'>".escape_textarea($acme_account_description)."</textarea>\n";
	echo "	<br />\n";
	echo "	".$text['description-acme_account_description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br /><br />\n";

	if ($action == "update") {
		echo "<input type='hidden' name='acme_account_uuid' value='".escape($acme_account_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
