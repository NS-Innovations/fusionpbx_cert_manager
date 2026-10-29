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
	if (!(permission_exists('certificate_add') || permission_exists('certificate_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$certificate_name = '';
	$certificate_source = 'acme';
	$certificate_domains = '';
	$acme_account_uuid = '';
	$certificate_challenge = 'http-01';
	$dns_provider_uuid = '';
	$certificate_key_type = 'ec-256';
	$certificate_renew_days = '';
	$certificate_enabled = 'true';
	$certificate_description = '';
	$certificate = [];

//set variables from the http get parameters
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_GET['order_by'] ?? ''));
	$order = ($_GET['order'] ?? '') === 'desc' ? 'desc' : 'asc';
	$search = $_GET['search'] ?? '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($order_by)) {
		$param['order_by'] = $order_by;
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	$query_string = http_build_query($param);

//action add or update
	if (is_uuid($_REQUEST["id"] ?? '')) {
		$action = "update";
		$certificate_uuid = $_REQUEST["id"];
	}
	else {
		$action = "add";
	}

//get posted data
	if (count($_POST) > 0) {
		$certificate_name = $_POST['certificate_name'] ?? '';
		$certificate_domains = implode("\n", certificates::split_domains($_POST['certificate_domains'] ?? ''));
		$acme_account_uuid = $_POST['acme_account_uuid'] ?? '';
		$certificate_challenge = $_POST['certificate_challenge'] ?? '';
		$dns_provider_uuid = $_POST['dns_provider_uuid'] ?? '';
		$certificate_key_type = $_POST['certificate_key_type'] ?? '';
		$certificate_renew_days = trim($_POST['certificate_renew_days'] ?? '');
		$certificate_enabled = $_POST['certificate_enabled'] ?? 'false';
		$certificate_description = $_POST['certificate_description'] ?? '';
		$certificate_renew_now = ($_POST['certificate_renew_now'] ?? '') == 'true';
	}

//process the http post data
	if (count($_POST) > 0 && empty($_POST["persistformvar"])) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: certificates.php'.($query_string ? '?'.$query_string : ''));
				exit;
			}

		//get the saved certificate, to know whether it has to be ordered again
			$saved = [];
			if ($action == 'update') {
				$sql = "select certificate_domains, certificate_key_type from v_certificates ";
				$sql .= "where certificate_uuid = :certificate_uuid ";
				$parameters['certificate_uuid'] = $certificate_uuid;
				$saved = $database->select($sql, $parameters, 'row') ?: [];
				unset($sql, $parameters);
			}

		//check for all required data
			$msg = '';
			$domains = certificates::split_domains($certificate_domains);
			if (empty($certificate_name)) { $msg .= $text['message-required']." ".$text['label-name']."<br>\n"; }
			if (empty($domains)) { $msg .= $text['message-required']." ".$text['label-certificate_domains']."<br>\n"; }
			foreach (certificates::invalid_domains($domains) as $domain) {
				$msg .= $text['message-certificate_domain_invalid']." ".escape($domain)."<br>\n";
			}
			if (!is_uuid($acme_account_uuid)) { $msg .= $text['message-required']." ".$text['label-acme_account']."<br>\n"; }
			if (!in_array($certificate_challenge, ['http-01', 'dns-01'], true)) { $msg .= $text['message-required']." ".$text['label-certificate_challenge']."<br>\n"; }
			if ($certificate_challenge == 'http-01' && preg_grep('/^\*\./', $domains)) { $msg .= $text['message-certificate_wildcard_dns']."<br>\n"; }
			if ($certificate_challenge == 'dns-01' && !is_uuid($dns_provider_uuid)) { $msg .= $text['message-required']." ".$text['label-dns_provider']."<br>\n"; }
			if (!in_array($certificate_key_type, acme_client::key_types, true)) { $msg .= $text['message-required']." ".$text['label-certificate_key_type']."<br>\n"; }
			if ($certificate_renew_days !== '' && (!ctype_digit($certificate_renew_days) || (int) $certificate_renew_days < 1)) { $msg .= $text['message-certificate_renew_days_invalid']."<br>\n"; }
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
			if ($action == "add" && permission_exists('certificate_add')) {
				$certificate_uuid = uuid();
			}
			$array['certificates'][0]['certificate_uuid'] = $certificate_uuid;
			$array['certificates'][0]['certificate_name'] = $certificate_name;
			$array['certificates'][0]['certificate_source'] = 'acme';
			$array['certificates'][0]['certificate_domains'] = $certificate_domains;
			$array['certificates'][0]['acme_account_uuid'] = $acme_account_uuid;
			$array['certificates'][0]['certificate_challenge'] = $certificate_challenge;
			$array['certificates'][0]['dns_provider_uuid'] = $certificate_challenge == 'dns-01' ? $dns_provider_uuid : null;
			$array['certificates'][0]['certificate_key_type'] = $certificate_key_type;
			$array['certificates'][0]['certificate_renew_days'] = $certificate_renew_days !== '' ? (int) $certificate_renew_days : null;
			$array['certificates'][0]['certificate_enabled'] = $certificate_enabled;
			$array['certificates'][0]['certificate_description'] = $certificate_description;

		//order a new certificate, or a renewal when the names or the key type change or one was asked for
			$names_changed = !empty($saved) && certificates::split_domains($saved['certificate_domains']) != $domains;
			$key_changed = !empty($saved) && $saved['certificate_key_type'] != $certificate_key_type;
			if ($action == 'add') {
				$array['certificates'][0]['certificate_status'] = 'pending';
			}
			if ($action == 'add' || $names_changed || $key_changed || ($certificate_renew_now && permission_exists('certificate_renew'))) {
				$array['certificates'][0]['certificate_renew'] = 'true';
				$array['certificates'][0]['certificate_attempts'] = 0;
				$array['certificates'][0]['certificate_next_attempt'] = null;
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
			if ($action == 'add' || $names_changed || $key_changed || $certificate_renew_now) {
				message::add($text['message-certificate_renew_requested']);
			}

		//redirect the browser
			header("Location: certificate_edit.php?id=".urlencode($certificate_uuid).($query_string ? '&'.$query_string : ''));
			exit;
	}

//pre-populate the form
	if (!empty($certificate_uuid) && empty($_POST["persistformvar"])) {
		$sql = "select certificate_name, certificate_source, certificate_domains, acme_account_uuid, certificate_challenge, ";
		$sql .= "dns_provider_uuid, certificate_key_type, certificate_renew_days, certificate_serial, certificate_issuer, ";
		$sql .= "to_char(certificate_not_before, 'YYYY-MM-DD HH24:MI') as certificate_not_before, ";
		$sql .= "to_char(certificate_not_after, 'YYYY-MM-DD HH24:MI') as certificate_not_after, ";
		$sql .= "floor(extract(epoch from (certificate_not_after - now())) / 86400) as days_left, ";
		$sql .= "certificate_status, certificate_message, cast(certificate_renew as text) as certificate_renew, ";
		$sql .= "to_char(certificate_next_attempt, 'YYYY-MM-DD HH24:MI') as certificate_next_attempt, ";
		$sql .= "cast(certificate_enabled as text) as certificate_enabled, certificate_description ";
		$sql .= "from v_certificates ";
		$sql .= "where certificate_uuid = :certificate_uuid ";
		$parameters['certificate_uuid'] = $certificate_uuid;
		$row = $database->select($sql, $parameters, 'row');
		if (is_array($row) && @sizeof($row) != 0) {
			$certificate = $row;
			$certificate_name = $row["certificate_name"];
			$certificate_source = $row["certificate_source"];
			$certificate_domains = $row["certificate_domains"];
			$acme_account_uuid = $row["acme_account_uuid"];
			$certificate_challenge = $row["certificate_challenge"];
			$dns_provider_uuid = $row["dns_provider_uuid"];
			$certificate_key_type = $row["certificate_key_type"];
			$certificate_renew_days = $row["certificate_renew_days"];
			$certificate_enabled = $row["certificate_enabled"];
			$certificate_description = $row["certificate_description"];
		}
		unset($sql, $parameters, $row);
	}

//get the accounts and the providers to choose from
	$sql = "select acme_account_uuid, acme_account_name from v_acme_accounts ";
	$sql .= "where acme_account_enabled = true or acme_account_uuid = :acme_account_uuid ";
	$sql .= "order by acme_account_name asc ";
	$parameters['acme_account_uuid'] = is_uuid($acme_account_uuid) ? $acme_account_uuid : null;
	$acme_accounts = $database->select($sql, $parameters, 'all') ?: [];
	unset($sql, $parameters);

	$sql = "select dns_provider_uuid, dns_provider_name from v_dns_providers ";
	$sql .= "where dns_provider_enabled = true or dns_provider_uuid = :dns_provider_uuid ";
	$sql .= "order by dns_provider_name asc ";
	$parameters['dns_provider_uuid'] = is_uuid($dns_provider_uuid) ? $dns_provider_uuid : null;
	$dns_providers = $database->select($sql, $parameters, 'all') ?: [];
	unset($sql, $parameters);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-certificate'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-certificate']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'certificates.php'.($query_string ? '?'.$query_string : '')]);
	if ($action == 'update' && $certificate_source == 'acme' && permission_exists('certificate_renew')) {
		echo button::create(['type'=>'submit','label'=>$text['button-renew'],'icon'=>$settings->get('theme', 'button_icon_refresh'),'id'=>'btn_renew','name'=>'certificate_renew_now','value'=>'true']);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-certificate']."\n";
	echo "<br /><br />\n";

	//show the state of the certificate
	if ($action == 'update' && !empty($certificate)) {
		$status = $certificate['certificate_status'] == 'valid' && $certificate['days_left'] !== null && $certificate['days_left'] < 0 ? 'expired' : $certificate['certificate_status'];
		echo "<div class='card'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-status']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	".escape($text['option-certificate_status_'.$status] ?? $status)."\n";
		if ($certificate['certificate_renew'] == 'true') {
			echo "	<br />".$text['message-certificate_renew_requested']."\n";
		}
		if (!empty($certificate['certificate_message'])) {
			echo "	<br />".escape($certificate['certificate_message'])."\n";
		}
		if (!empty($certificate['certificate_next_attempt'])) {
			echo "	<br />".$text['label-certificate_next_attempt']." ".escape($certificate['certificate_next_attempt'])."\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
		if (!empty($certificate['certificate_serial'])) {
			echo "<tr>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_issuer']."</td>\n";
			echo "<td class='vtable' align='left'>".escape($certificate['certificate_issuer'])."</td>\n";
			echo "</tr>\n";
			echo "<tr>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_serial']."</td>\n";
			echo "<td class='vtable' align='left'>".escape($certificate['certificate_serial'])."</td>\n";
			echo "</tr>\n";
			echo "<tr>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_validity']."</td>\n";
			echo "<td class='vtable' align='left'>".escape($certificate['certificate_not_before'])." - ".escape($certificate['certificate_not_after'])." (".escape($certificate['days_left'])." ".$text['label-days'].")</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-name']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='certificate_name' maxlength='255' value=\"".escape($certificate_name)."\" required='required'>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_domains']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='certificate_domains' rows='4' style='width: 350px;'>".escape_textarea($certificate_domains)."</textarea>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_domains']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-acme_account']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='acme_account_uuid'>\n";
	echo "		<option value=''></option>\n";
	foreach ($acme_accounts as $row) {
		echo "		<option value='".escape($row['acme_account_uuid'])."' ".($row['acme_account_uuid'] == $acme_account_uuid ? "selected='selected'" : null).">".escape($row['acme_account_name'])."</option>\n";
	}
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_acme_account']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_challenge']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='certificate_challenge' id='certificate_challenge' onchange=\"document.getElementById('tr_dns_provider').style.display = this.value == 'dns-01' ? '' : 'none';\">\n";
	foreach (['http-01', 'dns-01'] as $challenge) {
		echo "		<option value='".$challenge."' ".($challenge == $certificate_challenge ? "selected='selected'" : null).">".$text['option-certificate_challenge_'.str_replace('-', '_', $challenge)]."</option>\n";
	}
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_challenge']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id='tr_dns_provider' style='".($certificate_challenge == 'dns-01' ? '' : 'display: none;')."'>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-dns_provider']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='dns_provider_uuid'>\n";
	echo "		<option value=''></option>\n";
	foreach ($dns_providers as $row) {
		echo "		<option value='".escape($row['dns_provider_uuid'])."' ".($row['dns_provider_uuid'] == $dns_provider_uuid ? "selected='selected'" : null).">".escape($row['dns_provider_name'])."</option>\n";
	}
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_dns_provider']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_key_type']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='certificate_key_type'>\n";
	foreach (acme_client::key_types as $key_type) {
		echo "		<option value='".$key_type."' ".($key_type == $certificate_key_type ? "selected='selected'" : null).">".$text['option-key_type_'.str_replace('-', '_', $key_type)]."</option>\n";
	}
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_key_type']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-certificate_renew_days']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='number' name='certificate_renew_days' min='1' max='365' value=\"".escape($certificate_renew_days)."\" placeholder=\"".escape($settings->get('cert_manager', 'renew_days', 30))."\">\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_renew_days']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='certificate_enabled'>\n";
	echo "		<option value='true' ".($certificate_enabled != 'false' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "		<option value='false' ".($certificate_enabled == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='certificate_description'>".escape_textarea($certificate_description)."</textarea>\n";
	echo "	<br />\n";
	echo "	".$text['description-certificate_description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br /><br />\n";

	if ($action == "update") {
		echo "<input type='hidden' name='certificate_uuid' value='".escape($certificate_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
