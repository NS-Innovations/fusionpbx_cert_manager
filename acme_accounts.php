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
	if (!permission_exists('acme_account_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from the http get parameters
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_GET['order_by'] ?? 'acme_account_name'));
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
	if (!empty($_POST['acme_accounts'])) {
		$action = $_POST['action'];
		$acme_accounts = $_POST['acme_accounts'];
	}

//process the http post data by action
	if (!empty($action) && !empty($acme_accounts)) {
		switch ($action) {
			case 'toggle':
				$obj = new acme_accounts;
				$obj->toggle($acme_accounts);
				break;
			case 'delete':
				$obj = new acme_accounts;
				$obj->delete($acme_accounts);
				break;
		}

		header('Location: acme_accounts.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//get the filtered account count
	$parameters = [];
	$sql = "select count(acme_account_uuid) from v_acme_accounts ";
	$sql .= "where true ";
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= "lower(acme_account_name) like :search ";
		$sql .= "or lower(acme_account_email) like :search ";
		$sql .= "or lower(acme_account_description) like :search ";
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
	$sql = "select acme_account_uuid, acme_account_name, acme_account_directory, acme_account_email, ";
	$sql .= "acme_account_status, cast(acme_account_enabled as text), acme_account_description ";
	$sql .= "from v_acme_accounts ";
	$sql .= "where true ";
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= "lower(acme_account_name) like :search ";
		$sql .= "or lower(acme_account_email) like :search ";
		$sql .= "or lower(acme_account_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$sql .= order_by($order_by, $order);
	$sql .= limit_offset($rows_per_page, $offset);
	$acme_accounts = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//name the directories that are presets
	$preset_names = [];
	foreach (acme_accounts::presets() as $key => $url) {
		$preset_names[$url] = $text[$key];
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-acme_accounts'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-acme_accounts']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'certificates.php']);
	if (permission_exists('acme_account_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'acme_account_edit.php']);
	}
	if (permission_exists('acme_account_edit') && $acme_accounts) {
		echo button::create(['type'=>'button','label'=>$text['button-toggle'],'icon'=>$settings->get('theme', 'button_icon_toggle'),'id'=>'btn_toggle','name'=>'btn_toggle','style'=>'display: none;','onclick'=>"modal_open('modal-toggle','btn_toggle');"]);
	}
	if (permission_exists('acme_account_delete') && $acme_accounts) {
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

	if (permission_exists('acme_account_edit') && $acme_accounts) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('acme_account_delete') && $acme_accounts) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-acme_accounts']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('acme_account_edit') || permission_exists('acme_account_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".!empty($acme_accounts ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
	}
	echo th_order_by('acme_account_name', $text['label-name'], $order_by, $order, null, null, $query_string);
	echo th_order_by('acme_account_directory', $text['label-acme_account_directory'], $order_by, $order, null, "class='hide-sm-dn'", $query_string);
	echo th_order_by('acme_account_email', $text['label-email'], $order_by, $order, null, null, $query_string);
	echo th_order_by('acme_account_status', $text['label-status'], $order_by, $order, null, null, $query_string);
	echo th_order_by('acme_account_enabled', $text['label-enabled'], $order_by, $order, null, "class='center'", $query_string);
	echo th_order_by('acme_account_description', $text['label-description'], $order_by, $order, null, "class='hide-sm-dn'", $query_string);
	if (permission_exists('acme_account_edit') && $settings->get('theme', 'list_row_edit_button', false)) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (is_array($acme_accounts) && @sizeof($acme_accounts) != 0) {
		$x = 0;
		foreach ($acme_accounts as $row) {
			$list_row_url = '';
			if (permission_exists('acme_account_edit')) {
				$list_row_url = "acme_account_edit.php?id=".urlencode($row['acme_account_uuid']).($query_string ? '&'.$query_string : '');
			}
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('acme_account_edit') || permission_exists('acme_account_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='acme_accounts[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='acme_accounts[$x][uuid]' value='".escape($row['acme_account_uuid'])."' />\n";
				echo "	</td>\n";
			}
			echo "	<td>";
			if (permission_exists('acme_account_edit')) {
				echo "<a href='".$list_row_url."' title=\"".$text['button-edit']."\">".escape($row['acme_account_name'])."</a>";
			}
			else {
				echo escape($row['acme_account_name']);
			}
			echo "	</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($preset_names[$row['acme_account_directory']] ?? $row['acme_account_directory'])."&nbsp;</td>\n";
			echo "	<td>".escape($row['acme_account_email'])."&nbsp;</td>\n";
			echo "	<td>".escape(!empty($row['acme_account_status']) ? ($text['option-status_'.$row['acme_account_status']] ?? $row['acme_account_status']) : $text['option-status_unregistered'])."</td>\n";
			if (permission_exists('acme_account_edit')) {
				echo "	<td class='no-link center'>";
				echo button::create(['type'=>'submit','class'=>'link','label'=>$text['label-'.$row['acme_account_enabled']],'title'=>$text['button-toggle'],'onclick'=>"list_self_check('checkbox_".$x."'); list_action_set('toggle'); list_form_submit('form_list')"]);
			}
			else {
				echo "	<td class='center'>";
				echo $text['label-'.$row['acme_account_enabled']];
			}
			echo "	</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['acme_account_description'])."&nbsp;</td>\n";
			if (permission_exists('acme_account_edit') && $settings->get('theme', 'list_row_edit_button', false)) {
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($acme_accounts);
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
