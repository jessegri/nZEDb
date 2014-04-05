<?php
require_once './config.php';
$page = new AdminPage();
$page->title = 'Sharing Settings';

use nzedb\db\DB;
$db = new DB();

$offset = (isset($_GET['offset']) ? $_GET['offset'] : 0);

$allSites = $db->query(sprintf('SELECT * FROM sharing_sites LIMIT %d OFFSET %d', 25, $offset));
if (count($allSites) === 0) {
	$allSites = false;
}

$ourSite = $db->queryOneRow('SELECT * FROM sharing');

if (!empty($_POST)) {
	if (!empty($_POST['sharing_name'])) {
		$site_name = trim($_POST['sharing_name']);
	} else {
		$site_name = $ourSite['site_name'];
	}
	if (!empty($_POST['sharing_maxpush'])) {
		$max_push = trim($_POST['sharing_maxpush']);
	} else {
		$max_push = $ourSite['max_push'];
	}
	if (!empty($_POST['sharing_maxpull'])) {
		$max_pull = trim($_POST['sharing_maxpull']);
	} else {
		$max_pull = $ourSite['max_pull'];
	}
	$db->queryExec(sprintf('UPDATE sharing SET site_name = %s, max_push = %d, max_pull = %d', $db->escapeString($site_name), $max_push, $max_pull));
	$ourSite = $db->queryOneRow('SELECT * FROM sharing');
}

$total = $db->queryOneRow('SELECT COUNT(*) AS total FROM sharing_sites');

$page->smarty->assign('pagertotalitems', ($total === false ? 0 : $total['total']));
$page->smarty->assign('pageroffset', $offset);
$page->smarty->assign('pageritemsperpage', 25);
$page->smarty->assign('pagerquerybase', WWW_TOP . "/sharing.php?offset=");

$pager = $page->smarty->fetch("pager.tpl");
$page->smarty->assign('pager', $pager);

$page->smarty->assign(array('local' => $ourSite, 'sites' => $allSites));

$page->content = $page->smarty->fetch('sharing.tpl');
$page->render();