<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
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
 *    \file       salaryimport/salaryimportconfirm.php
 *    \ingroup    salaryimport
 *    \brief      Confirmation and execution page for salary import
 */

$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

// Load service classes
require_once __DIR__.'/class/SalaryImportService.class.php';

// Load translation files required by the page
$langs->loadLangs(array("salaryimport@salaryimport"));

$action = GETPOST('action', 'aZ09');

$max = 5;
$now = dol_now();

// Security check - Protection if external user
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) accessforbidden();
if ($user->socid > 0) $socid = $user->socid;
if (!isModEnabled('salaryimport')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('salaryimport', 'import', 'read')) {
	accessforbidden();
}
if (empty($user->admin)) {
	accessforbidden('Must be admin');
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("SalaryImportArea"));

try {
	$t_data = GETPOST('t_data', 'array');

	if (empty($t_data)) {
		throw new Exception('Aucune donnée à importer');
	}

	// Display preview table
	$labels = array(
		'Nom du salarié',
		'Date de paiement',
		'Montant',
		'Type de paiement',
		'Libellé',
		'Date de début',
		'Date de fin',
		'Payé',
		'PDF'
	);

	print '<table class="noborder" width="100%">';
	print '<tr class="liste_titre">';
	foreach ($labels as $label) {
		print '<td>' . htmlspecialchars($label) . '</td>';
	}
	print '</tr>';

	foreach ($t_data as $row) {
		print '<tr class="oddeven">';
		print '<td>' . htmlspecialchars($row['userName']) . '</td>';
		print '<td>' . htmlspecialchars($row['datep']) . '</td>';
		print '<td>' . htmlspecialchars($row['amount']) . '</td>';
		print '<td>' . htmlspecialchars($row['typepaymentcode']) . '</td>';
		print '<td>' . htmlspecialchars($row['label']) . '</td>';
		print '<td>' . htmlspecialchars($row['datesp']) . '</td>';
		print '<td>' . htmlspecialchars($row['dateep']) . '</td>';
		print '<td>' . ($row['paye'] ? 'Oui' : 'Non') . '</td>';

		$pdfDisplay = '';
		if (!empty($row['pdf'])) {
			$pdfDisplay = basename($row['pdf']);
		}
		print '<td>' . htmlspecialchars($pdfDisplay) . '</td>';
		print '</tr>';
	}
	print '</table>';

	// Initialize service and execute import
	$service = new SalaryImportService($db, $user);

	$importedCount = $service->executeImport($t_data);

	if ($importedCount < 0) {
		throw new Exception(implode('<br />', $service->errors));
	}

	$db->commit();

	print '<div class="info">';
	print '<p>Import terminé avec succès: ' . $importedCount . ' salaire(s) importé(s)</p>';
	print '</div>';

	print '<p><a href="'.dol_buildpath('/custom/salaryimport/salaryimportindex.php', 1).'" class="button">Retour</a></p>';

} catch (Exception $e) {
	$db->rollback();

	print "<h1>Erreur lors de l'import</h1>";
	print "<p>Erreur : " . $e->getMessage() . "</p>";

	print '<p><a href="'.dol_buildpath('/custom/salaryimport/salaryimportindex.php', 1).'" class="button">Retour</a></p>';
}

// End of page
llxFooter();
$db->close();
