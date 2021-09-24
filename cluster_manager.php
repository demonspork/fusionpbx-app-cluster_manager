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
	Portions created by the Initial Developer are Copyright (C) 2018 - 2020
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";
	require_once "resources/functions/functions.php";


//check permission
	if (permission_exists('cluster_all') && permission_exists('cluster_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the http post data
	if (is_array($_POST['domains'])) {
		$action = $_POST['action'];
		$search = $_POST['search'];
		$domains = $_POST['domains'];
	}

//get the node list - should eventually be its own DB table, but it's shoved into default settings for now. there are some more things to add to it.
	$nodes_raw = explode(";", $_SESSION['cluster_manager']['node_list']['text']);
	foreach ($nodes_raw as $node) {
		$node = explode(":", $node);
		$nodes[$node['1']] = $node;
	}

//process the http post data by action
	if ($action != '' && is_array($domains) && @sizeof($domains) != 0) {
		switch ($action) {
			case 'update':
				if (permission_exists('cluster_edit')) {
					set_dns($_POST['domains'], $nodes, $_SESSION['cluster_manager']['hostedzoneid']['text']);
					sync_dsiprouter_destinations($_POST['domains']);
				}
				break;
			case 'toggle':
				if (permission_exists('cluster_edit')) {
					$obj = new domains;
					$obj->toggle($domains);
				}
				break;
			case 'delete':
				if (permission_exists('cluster_delete')) {
					$obj = new domains;
					$obj->delete($domains);
				}
				break;
			case 'kamreload':
				if (permission_exists('cluster_edit')) {
					reload_kamailio();
				}
		}

		header('Location: cluster_manager.php'.($search != '' ? '?search='.urlencode($search) : null));
		exit;
	}

//get order and order by
	$order_by = $_GET["order_by"];
	$order = $_GET["order"];

//add the search string
	if (isset($_GET["search"])) {
		$search =  strtolower($_GET["search"]);
		$sql_search = " (";
		$sql_search .= "	lower(domain_name) like :search ";
		$sql_search .= "	or lower(domain_description) like :search ";
		$sql_search .= ") ";
		$parameters['search'] = '%'.$search.'%';
	}

//get the count
	$sql = "select count(domain_uuid) from v_domains ";
	$sql .= "where domain_enabled = true ";
	if (isset($sql_search)) {
		$sql .= " AND ".$sql_search;
	}
	$database = new database;
	$num_rows = $database->select($sql, $parameters, 'column');

//prepare to page the results
	$rows_per_page = ($_SESSION['domain']['paging']['numeric'] != '') ? $_SESSION['domain']['paging']['numeric'] : 50;
	$param = $search ? "&search=".$search : null;
	$page = is_numeric($_GET['page']) ? $_GET['page'] : 0;
	list($paging_controls, $rows_per_page) = paging($num_rows, $param, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//get the list
	$sql = "select domain_uuid, domain_name, cast(domain_enabled as text), domain_description ";
	$sql .= "from v_domains ";
	if (isset($sql_search)) {
		$sql .= "where ".$sql_search;
	}
	$sql .= order_by($order_by, $order, 'domain_name', 'asc');
	$sql .= limit_offset($rows_per_page, $offset);
	$database = new database;
	$domains = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//get the dns status
	$dns_status = dns_status($domains, $_SESSION['cluster_manager']['hostedzoneid']['text']);

//get the destinations and inboundmappings per domain
	if (is_array($domains) && @sizeof($domains) != 0) {
		//get the dsiprouter destinations array - full array, I can't search through the API
		$dsiprouter_destinations = get_dsiprouter_destinations();
		// header('Content-type: text/javascript');
		// echo json_encode($dsiprouter_destinations->data, JSON_PRETTY_PRINT);
		// exit;
		$x = 0;
		foreach ($domains as $row) {
			if ($row['domain_enabled'] == "false"){
				continue;
			}
			$sql = "select * from v_destinations";
			$sql .= " where domain_uuid = :domain_uuid";
			$sql .= " and destination_type = 'inbound'";
			$sql .= " and destination_enabled::bool = true";
			$parameters['domain_uuid'] = $row['domain_uuid'];
			$database = new database;
			$domain_destinations[$row['domain_name']] = $database->select($sql, $parameters, 'all');
			unset($sql, $parameters);

			$x = count($dsiprouter_destinations->data);
			foreach ($domain_destinations[$row['domain_name']] as $row_destination) {
				$y = 0;
				foreach ($dsiprouter_destinations->data as $mapping) {
					$y++;
					if($row_destination['destination_number'] == $mapping->did) {
						$synced_destnations[$row['domain_name']][$mapping->did] = $mapping;
					}
				}
			}

		}

	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-cluster_manager'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-cluster_manager']." (".$num_rows.")</b></div>\n";
	echo "	<div class='actions'>\n";
	if ($kamreload == "true") {
		echo "<p style='color:red'>Kamailio Needs Reloaded</p>";
	}
	if (permission_exists('cluster_edit') && $domains) {
		echo button::create(['type'=>'button','label'=>'Update','icon'=>$_SESSION['theme']['button_icon_toggle'],'name'=>'btn_update','onclick'=>"list_action_set('update'); list_form_submit('form_list');"]);
	}
	echo button::create(['type'=>'button','label'=>'Reload Kamailio','icon'=>'sync-alt','name'=>'btn_kamreload','onclick'=>"list_action_set('kamreload'); list_form_submit('form_list');"]);
	echo 		"<form id='form_search' class='inline' method='get'>\n";
	echo 		"<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown='list_search_reset();'>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$_SESSION['theme']['button_icon_search'],'type'=>'submit','id'=>'btn_search','style'=>($search != '' ? 'display: none;' : null)]);
	echo button::create(['label'=>$text['button-reset'],'icon'=>$_SESSION['theme']['button_icon_reset'],'type'=>'button','id'=>'btn_reset','link'=>'cluster_manager.php','style'=>($search == '' ? 'display: none;' : null)]);
	if ($paging_controls_mini != '') {
		echo 	"<span style='margin-left: 15px;'>".$paging_controls_mini."</span>\n";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (permission_exists('cluster_edit') && $domains) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
 	if (permission_exists('cluster_delete') && $domains) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete_domain','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
 	}

	echo $text['description-cluster_manager']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";
	echo "<input type='hidden' name='search' value=\"".escape($search)."\">\n";

	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('cluster_edit') || permission_exists('cluster_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle();' ".($domains ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
	}
	if ($_GET['show'] == 'all' && permission_exists('cluster_all')) {
		echo th_order_by('domain_name', $text['label-domain'], $order_by, $order);
	}
	echo th_order_by('domain_name', $text['label-domain_name'], $order_by, $order);
	echo th_order_by('cluster_manager_node_primary', 'Primary', $order_by, $order);
	echo th_order_by('dns_status', 'DNS Status', $order_by, $order); 
	echo "  <th>SBC Status</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-domain_description']."</th>\n";
	if (permission_exists('cluster_edit') && $_SESSION['theme']['list_row_edit_button']['boolean'] == 'true') {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (is_array($domains) && @sizeof($domains) != 0) {
		$x = 0;
		foreach ($domains as $row) {
			if ($row['domain_enabled'] == "false"){
				continue;
			}

			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('cluster_edit') || permission_exists('cluster_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='domains[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='domains[$x][domain_name]' value='".escape($row['domain_name'])."' />\n";
				echo "	</td>\n";
			}
			if ($_GET['show'] == 'all' && permission_exists('cluster_all')) {
				echo "	<td>".escape($_SESSION['domains'][$row['domain_uuid']]['domain_name'])."</td>\n";
			}
			echo "	<td>\n";
			if (permission_exists('cluster_edit')) {
				echo "	<a href='".$list_row_url."' title=\"".$text['button-edit']."\">".escape($row['domain_name'])."</a>\n";
			}
			else {
				echo "	".escape($row['domain_name']);
			}
			echo "	</td>\n";
			echo "  <td>\n";
			echo " <select name='domains[$x][selected_node]'> \n"; // primary node selector
			echo "    <option disabled selected value>---</option>";
			foreach ($nodes as $node) {
				echo "    <option ";
				if ($node['1'] == $dns_status[$row['domain_name']]['a_value']) {
					echo "selected='true' ";
				}
				echo "value=".$node['1'].">".$node[0]."</option> \n";
			}
			echo "  <td>\n";
			echo "<span title='".$dns_status[$row['domain_name']]['a_value']."'>FQDN</span>";
			echo $dns_status[$row['domain_name']]['A'] ? "&#10004;" : "&#10060;";
			if ($_SESSION['cluster_manager']['srv_records']['bool'] == 'true') {
				echo "<span title='".$dns_status[$row['domain_name']]['udp_value']."'>UDP</span>";
				echo $dns_status[$row['domain_name']]['udp'] ? "&#10004;" : "&#10060;";
				echo "<span title='".$dns_status[$row['domain_name']]['tcp_value']."'>TCP</span>";
				echo $dns_status[$row['domain_name']]['tcp'] ? "&#10004;" : "&#10060;";
				echo "<span title='".$dns_status[$row['domain_name']]['tls_value']."'>TLS</span>";
				echo $dns_status[$row['domain_name']]['tls'] ? "&#10004;" : "&#10060;";
			}
			echo "	</td>\n";
			echo "  <td>\n";
			echo count($synced_destnations[$row['domain_name']])." / ".count($domain_destinations[$row['domain_name']]);
			echo "  </td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['domain_description'])."</td>\n";
			if (permission_exists('cluster_edit') && $_SESSION['theme']['list_row_edit_button']['boolean'] == 'true') {
				echo "	<td class='action-button'>\n";
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$_SESSION['theme']['button_icon_edit'],'link'=>$list_row_url]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($domains);
	}

	echo "</table>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
