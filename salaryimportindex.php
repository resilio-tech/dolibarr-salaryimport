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
 *	\file       salaryimport/salaryimportindex.php
 *	\ingroup    salaryimport
 *	\brief      Home page of salaryimport top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
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

// Load translation files required by the page
$langs->loadLangs(array("salaryimport@salaryimport"));

// Security check
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
 * Actions
 */

// None


/*
 * View
 */

llxHeader("", $langs->trans("SalaryImportArea"));

print load_fiche_titre($langs->trans("SalaryImportArea"), '', 'salaryimport.png@salaryimport');

print '<div class="fichecenter">';
print '<form method="POST" action="/custom/salaryimport/salaryimportfile.php" enctype="multipart/form-data">';
print '<div>';
print '<label for="file">Fichier XLSX de salaires : </label>';
print '<input type="file" name="file" required>';
print '</div>';
print '<div>';
print '<label for="zipfile">Fichier ZIP avec PDF correspondants : </label>';
print '<input type="file" name="zip">';
print '</div>';
print '<input type="submit" value="Envoyer">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="add">';
print '</form>';
print '</div>';


// End of page
llxFooter();
$db->close();
