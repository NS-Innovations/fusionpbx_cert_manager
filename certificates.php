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
	require_once "resources/paging.php";

//check permissions
	if (!permission_exists('certificate_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from the http get parameters
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_GET['order_by'] ?? 'certificate_name'));
	$order = ($_GET['order'] ?? '') === 'desc' ? 'desc' : 'asc';
	$search = $_GET['search'] ?? '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($_GET['order_by'])) {
		$param['order_by'] = $order_by;
	}
	if (!empty($_GET['order'])) {
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	$query_string = http_build_query($param);

//get posted data
	if (!empty($_POST['certificates'])) {
		$action = $_POST['action'];
		$certificates = $_POST['certificates'];
	}

//process the http post data by action
	if (!empty($action) && !empty($certificates)) {
		switch ($action) {
			case 'renew':
				$obj = new certificates;
				$obj->renew($certificates);
				break;
			case 'toggle':
				$obj = new certificates;
				$obj->toggle($certificates);
				break;
			case 'delete':
				$obj = new certificates;
				$obj->delete($certificates);
				break;
		}

		header('Location: certificates.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//get the filtered certificate count
	$parameters = [];
	$sql = "select count(certificate_uuid) from v_certificates ";
	$sql .= "where true ";
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= "lower(certificate_name) like :search ";
		$sql .= "or lower(certificate_domains) like :search ";
		$sql .= "or lower(certificate_issuer) like :search ";
		$sql .= "or lower(certificate_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$num_rows = $database->select($sql, $parameters, 'column');
	unset($sql, $parameters);

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//get the list
	$parameters = [];
	$sql = "select certificate_uuid, certificate_name, certificate_source, certificate_domains, certificate_issuer, ";
	$sql .= "to_char(certificate_not_after, 'YYYY-MM-DD') as certificate_expires, ";
	$sql .= "floor(extract(epoch from (certificate_not_after - now())) / 86400) as days_left, ";
	$sql .= "certificate_status, cast(certificate_enabled as text), certificate_description ";
	$sql .= "from v_certificates ";
	$sql .= "where true ";
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= "lower(certificate_name) like :search ";
		$sql .= "or lower(certificate_domains) like :search ";
		$sql .= "or lower(certificate_issuer) like :search ";
		$sql .= "or lower(certificate_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$sql .= order_by(($order_by == 'certificate_expires' ? 'certificate_not_after' : $order_by), $order);
	$sql .= limit_offset($rows_per_page, $offset);
	$certificates = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-certificates'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-certificates']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('acme_account_view')) {
		echo button::create(['type'=>'button','label'=>$text['title-acme_accounts'],'icon'=>$settings->get('theme', 'button_icon_user'),'id'=>'btn_acme_accounts','link'=>'acme_accounts.php']);
	}
	if (permission_exists('dns_provider_view')) {
		echo button::create(['type'=>'button','label'=>$text['title-dns_providers'],'icon'=>$settings->get('theme', 'button_icon_settings'),'id'=>'btn_dns_providers','link'=>'dns_providers.php']);
	}
	if (permission_exists('certificate_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'certificate_edit.php']);
	}
	if (permission_exists('certificate_renew') && $certificates) {
		echo button::create(['type'=>'button','label'=>$text['button-renew'],'icon'=>$settings->get('theme', 'button_icon_refresh'),'id'=>'btn_renew','name'=>'btn_renew','class'=>'+revealed','style'=>'display: none;','onclick'=>"modal_open('modal-renew','btn_renew');"]);
	}
	if (permission_exists('certificate_edit') && $certificates) {
		echo button::create(['type'=>'button','label'=>$text['button-toggle'],'icon'=>$settings->get('theme', 'button_icon_toggle'),'id'=>'btn_toggle','name'=>'btn_toggle','style'=>'display: none;','onclick'=>"modal_open('modal-toggle','btn_toggle');"]);
	}
	if (permission_exists('certificate_delete') && $certificates) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','name'=>'btn_delete','style'=>'display: none;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo "		<form id='form_search' class='inline' method='get'>\n";
	foreach ($param as $key => $value) {
		if (in_array($key, ['order_by', 'order'])) {
			echo "		<input type='hidden' name='".escape($key)."' value='".escape($value)."'>\n";
		}
	}
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown=''>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	if ($paging_controls_mini != '') {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (permission_exists('certificate_renew') && $certificates) {
		echo modal::create(['id'=>'modal-renew','title'=>$text['modal_title-renew'],'message'=>$text['modal_message-renew'],'actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_renew','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('renew'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('certificate_edit') && $certificates) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('certificate_delete') && $certificates) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-certificates']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('certificate_renew') || permission_exists('certificate_edit') || permission_exists('certificate_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".!empty($certificates ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
	}
	echo th_order_by('certificate_name', $text['label-name'], $order_by, $order, null, null, $query_string);
	echo th_order_by('certificate_domains', $text['label-certificate_domains'], $order_by, $order, null, null, $query_string);
	echo th_order_by('certificate_source', $text['label-certificate_source'], $order_by, $order, null, "class='hide-sm-dn'", $query_string);
	echo th_order_by('certificate_issuer', $text['label-certificate_issuer'], $order_by, $order, null, "class='hide-md-dn'", $query_string);
	echo th_order_by('certificate_expires', $text['label-certificate_expires'], $order_by, $order, null, null, $query_string);
	echo th_order_by('certificate_status', $text['label-status'], $order_by, $order, null, null, $query_string);
	echo th_order_by('certificate_enabled', $text['label-enabled'], $order_by, $order, null, "class='center'", $query_string);
	echo th_order_by('certificate_description', $text['label-description'], $order_by, $order, null, "class='hide-sm-dn'", $query_string);
	if (permission_exists('certificate_edit') && $settings->get('theme', 'list_row_edit_button', false)) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (is_array($certificates) && @sizeof($certificates) != 0) {
		$x = 0;
		foreach ($certificates as $row) {
			$list_row_url = '';
			if (permission_exists('certificate_edit')) {
				$list_row_url = "certificate_edit.php?id=".urlencode($row['certificate_uuid']).($query_string ? '&'.$query_string : '');
			}
			$domains = certificates::split_domains($row['certificate_domains']);
			$status = $row['certificate_status'] == 'valid' && $row['days_left'] !== null && $row['days_left'] < 0 ? 'expired' : $row['certificate_status'];
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('certificate_renew') || permission_exists('certificate_edit') || permission_exists('certificate_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='certificates[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='certificates[$x][uuid]' value='".escape($row['certificate_uuid'])."' />\n";
				echo "	</td>\n";
			}
			echo "	<td>";
			if (permission_exists('certificate_edit')) {
				echo "<a href='".$list_row_url."' title=\"".$text['button-edit']."\">".escape($row['certificate_name'])."</a>";
			}
			else {
				echo escape($row['certificate_name']);
			}
			echo "	</td>\n";
			echo "	<td>".escape($domains[0] ?? '').(count($domains) > 1 ? " +".(count($domains) - 1) : '')."&nbsp;</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($text['option-certificate_source_'.$row['certificate_source']] ?? $row['certificate_source'])."</td>\n";
			echo "	<td class='hide-md-dn'>".escape($row['certificate_issuer'])."&nbsp;</td>\n";
			echo "	<td>".escape($row['certificate_expires']).($row['days_left'] !== null ? " (".escape($row['days_left'])." ".$text['label-days'].")" : '')."&nbsp;</td>\n";
			echo "	<td>".escape($text['option-certificate_status_'.$status] ?? $status)."&nbsp;</td>\n";
			if (permission_exists('certificate_edit')) {
				echo "	<td class='no-link center'>";
				echo button::create(['type'=>'submit','class'=>'link','label'=>$text['label-'.$row['certificate_enabled']],'title'=>$text['button-toggle'],'onclick'=>"list_self_check('checkbox_".$x."'); list_action_set('toggle'); list_form_submit('form_list')"]);
			}
			else {
				echo "	<td class='center'>";
				echo $text['label-'.$row['certificate_enabled']];
			}
			echo "	</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['certificate_description'])."&nbsp;</td>\n";
			if (permission_exists('certificate_edit') && $settings->get('theme', 'list_row_edit_button', false)) {
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($certificates);
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
