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
	if (!(permission_exists('dns_provider_add') || permission_exists('dns_provider_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the installed provider types
	$types = dns_providers::types();

//set the defaults
	$dns_provider_name = '';
	$dns_provider_type = isset($types['cloudflare']) ? 'cloudflare' : (string) array_key_first($types);
	$dns_provider_values = [];
	$dns_provider_enabled = 'true';
	$dns_provider_description = '';
	$saved_values = [];

//action add or update
	if (is_uuid($_REQUEST["id"] ?? '')) {
		$action = "update";
		$dns_provider_uuid = $_REQUEST["id"];
	}
	else {
		$action = "add";
	}

//get posted data
	if (count($_POST) > 0) {
		$dns_provider_name = $_POST['dns_provider_name'] ?? '';
		$dns_provider_type = $_POST['dns_provider_type'] ?? '';
		$dns_provider_values = $_POST['dns_provider_settings'][$dns_provider_type] ?? [];
		$dns_provider_enabled = $_POST['dns_provider_enabled'] ?? 'false';
		$dns_provider_description = $_POST['dns_provider_description'] ?? '';
		$dns_provider_test = ($_POST['dns_provider_test'] ?? '') == 'true';
	}

//process the http post data
	if (count($_POST) > 0 && empty($_POST["persistformvar"])) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: dns_providers.php');
				exit;
			}

		//the stored secrets are needed to keep the ones left blank
			$crypto = new cert_manager_crypto(['settings' => $settings]);
			if (!$crypto->available()) {
				message::add($text['message-key_file_missing'], 'negative');
				header('Location: dns_providers.php');
				exit;
			}

		//get the saved settings of the same type
			if ($action == 'update') {
				$sql = "select dns_provider_type, dns_provider_settings from v_dns_providers ";
				$sql .= "where dns_provider_uuid = :dns_provider_uuid ";
				$parameters['dns_provider_uuid'] = $dns_provider_uuid;
				$row = $database->select($sql, $parameters, 'row');
				unset($sql, $parameters);
				if (is_array($row) && ($row['dns_provider_type'] ?? '') === $dns_provider_type) {
					$saved_values = dns_providers::decode_settings($row['dns_provider_settings'], $crypto);
				}
				unset($row);
			}

		//keep only the fields of the type, and the saved secret when its field is left blank
			$values = [];
			if (isset($types[$dns_provider_type])) {
				foreach ($types[$dns_provider_type]::fields() as $field) {
					$value = trim((string) ($dns_provider_values[$field['name']] ?? ''));
					if ($value === '' && !empty($field['secret'])) {
						$value = (string) ($saved_values[$field['name']] ?? '');
					}
					$values[$field['name']] = $value;
				}
			}

		//check for all required data
			$msg = '';
			if (empty($dns_provider_name)) { $msg .= $text['message-required']." ".$text['label-name']."<br>\n"; }
			if (!isset($types[$dns_provider_type])) {
				$msg .= $text['message-required']." ".$text['label-type']."<br>\n";
			}
			else {
				foreach ($types[$dns_provider_type]::fields() as $field) {
					if (!empty($field['required']) && $values[$field['name']] === '') {
						$msg .= $text['message-required']." ".($text['label-dns_provider_'.$dns_provider_type.'_'.$field['name']] ?? $field['name'])."<br>\n";
					}
				}
			}
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
			if ($action == "add" && permission_exists('dns_provider_add')) {
				$dns_provider_uuid = uuid();
			}
			$array['dns_providers'][0]['dns_provider_uuid'] = $dns_provider_uuid;
			$array['dns_providers'][0]['dns_provider_name'] = $dns_provider_name;
			$array['dns_providers'][0]['dns_provider_type'] = $dns_provider_type;
			$array['dns_providers'][0]['dns_provider_settings'] = dns_providers::encode_settings($values, $crypto);
			$array['dns_providers'][0]['dns_provider_enabled'] = $dns_provider_enabled;
			$array['dns_providers'][0]['dns_provider_description'] = $dns_provider_description;

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

		//test the settings when asked to
			if ($dns_provider_test) {
				try {
					$result = dns_providers::create($dns_provider_type, $values)->test();
					message::add($text['message-dns_provider_test_passed'].($result !== '' ? ' '.escape($result) : ''), 'positive', 7000);
				}
				catch (Exception $e) {
					message::add($text['message-dns_provider_test_failed'].' '.escape($e->getMessage()), 'negative', 7000);
				}
			}

		//redirect the browser
			header("Location: dns_provider_edit.php?id=".urlencode($dns_provider_uuid));
			exit;
	}

//pre-populate the form
	if (!empty($dns_provider_uuid) && empty($_POST["persistformvar"])) {
		$sql = "select dns_provider_name, dns_provider_type, dns_provider_settings, ";
		$sql .= "cast(dns_provider_enabled as text) as dns_provider_enabled, dns_provider_description ";
		$sql .= "from v_dns_providers ";
		$sql .= "where dns_provider_uuid = :dns_provider_uuid ";
		$parameters['dns_provider_uuid'] = $dns_provider_uuid;
		$row = $database->select($sql, $parameters, 'row');
		if (is_array($row) && @sizeof($row) != 0) {
			$dns_provider_name = $row["dns_provider_name"];
			$dns_provider_type = $row["dns_provider_type"];
			$dns_provider_enabled = $row["dns_provider_enabled"];
			$dns_provider_description = $row["dns_provider_description"];
			try {
				$saved_values = dns_providers::decode_settings($row["dns_provider_settings"], new cert_manager_crypto(['settings' => $settings]));
			}
			catch (Exception $e) {
				$saved_values = [];
				message::add($text['message-key_file_missing'], 'negative');
			}
			$dns_provider_values = $saved_values;
		}
		unset($sql, $parameters, $row);
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-dns_provider'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-dns_provider']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'dns_providers.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-test'],'icon'=>$settings->get('theme', 'button_icon_play'),'id'=>'btn_test','name'=>'dns_provider_test','value'=>'true']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-dns_provider']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-name']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='dns_provider_name' maxlength='255' value=\"".escape($dns_provider_name)."\" required='required'>\n";
	echo "	<br />\n";
	echo "	".$text['description-dns_provider_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-type']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='dns_provider_type' id='dns_provider_type' onchange='show_provider_fields();'>\n";
	foreach ($types as $type => $class_name) {
		echo "		<option value='".escape($type)."' ".($type == $dns_provider_type ? "selected='selected'" : null).">".escape($text['option-dns_provider_'.$type] ?? $type)."</option>\n";
	}
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-dns_provider_type']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	//the fields of every type, of which only those of the selected type are shown
	foreach ($types as $type => $class_name) {
		$values = $type == $dns_provider_type ? $dns_provider_values : [];
		if ($class_name::manual()) {
			echo "<tr class='provider_fields' data-type='".escape($type)."'>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>&nbsp;</td>\n";
			echo "<td class='vtable' align='left'>".($text['description-dns_provider_'.$type] ?? '')."</td>\n";
			echo "</tr>\n";
		}
		foreach ($class_name::fields() as $field) {
			$name = "dns_provider_settings[".escape($type)."][".escape($field['name'])."]";
			$label = $text['label-dns_provider_'.$type.'_'.$field['name']] ?? $field['name'];
			$value = (string) ($values[$field['name']] ?? '');
			echo "<tr class='provider_fields' data-type='".escape($type)."'>\n";
			echo "<td class='".(!empty($field['required']) ? 'vncellreq' : 'vncell')."' valign='top' align='left' nowrap='nowrap'>".escape($label)."</td>\n";
			echo "<td class='vtable' align='left'>\n";
			if (!empty($field['secret'])) {
				echo "	<input class='formfld' type='password' name=\"".$name."\" maxlength='1024' autocomplete='new-password' value='' placeholder=\"".($value !== '' ? $text['label-unchanged'] : '')."\">\n";
			}
			elseif (($field['type'] ?? '') == 'textarea') {
				echo "	<textarea class='formfld' name=\"".$name."\">".escape_textarea($value)."</textarea>\n";
			}
			else {
				echo "	<input class='formfld' type='text' name=\"".$name."\" maxlength='1024' value=\"".escape($value)."\">\n";
			}
			echo "	<br />\n";
			echo "	".($text['description-dns_provider_'.$type.'_'.$field['name']] ?? '')."\n";
			echo "</td>\n";
			echo "</tr>\n";
		}
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='dns_provider_enabled'>\n";
	echo "		<option value='true' ".($dns_provider_enabled != 'false' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "		<option value='false' ".($dns_provider_enabled == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-dns_provider_enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='dns_provider_description'>".escape_textarea($dns_provider_description)."</textarea>\n";
	echo "	<br />\n";
	echo "	".$text['description-dns_provider_description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br /><br />\n";

	if ($action == "update") {
		echo "<input type='hidden' name='dns_provider_uuid' value='".escape($dns_provider_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//show only the fields of the selected type
	echo "<script>\n";
	echo "	function show_provider_fields() {\n";
	echo "		var type = document.getElementById('dns_provider_type').value;\n";
	echo "		document.querySelectorAll('tr.provider_fields').forEach(function (row) {\n";
	echo "			row.style.display = row.getAttribute('data-type') == type ? '' : 'none';\n";
	echo "		});\n";
	echo "	}\n";
	echo "	show_provider_fields();\n";
	echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>
