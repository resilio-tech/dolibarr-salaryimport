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
 *    \brief      Home page of salaryimport top menu
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
require_once DOL_DOCUMENT_ROOT . '/salaries/class/salary.class.php';

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
if (!$user->hasRight('salaryimport', 'myobject', 'read')) {
	accessforbidden();
}
restrictedArea($user, 'salaryimport', 0, 'salaryimport_myobject', 'myobject', '', 'rowid');
if (empty($user->admin)) {
	accessforbidden('Must be admin');
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);
$object = new Salary($db);

llxHeader("", $langs->trans("SalaryImportArea"));

$filename_salary = GETPOST('filename_salary', 'alpha');
$filename_zip = GETPOST('filename_zip', 'alpha');
$foldername_zip = GETPOST('foldername_zip', 'alpha');
$dir = DOL_DATA_ROOT.'/salaryimport';
$pdfs = array();
try {
	$t_data = GETPOST('t_data', 'array');
//	$TData = array(); // all data from inputs
	$errors = array();

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
		print '<td>' . $label . '</td>';
	}
	print '</tr>';
	for ($row = 0; $row < count($t_data); $row++) {
		print '<tr class="oddeven">';
		print '<td>' . $t_data[$row]['userName'] . '</td>';
		print '<td>' . $t_data[$row]['datep'] . '</td>';
		print '<td>' . $t_data[$row]['amount'] . '</td>';
		print '<td>' . $t_data[$row]['typepaymentcode'] . '</td>';
		print '<td>' . $t_data[$row]['label'] . '</td>';
		print '<td>' . $t_data[$row]['datesp'] . '</td>';
		print '<td>' . $t_data[$row]['dateep'] . '</td>';
		print '<td>' . ($t_data[$row]['paye'] ? 'Oui' : 'Non') . '</td>';
		print '<td>' . (substr($t_data[$row]['pdf'], strrpos($t_data[$row]['pdf'], '/') + 1)) . '</td>';
		print '</tr>';
	}
	print '</table>';

	for ($row = 0; $row < count($t_data); $row++) {
		$userId = intval($t_data[$row]['userId']);
		$userName = $t_data[$row]['userName'];
		$datep = $t_data[$row]['datep'];
		$amount = floatval($t_data[$row]['amount']);
		$typepayment = intval($t_data[$row]['typepayment']);
		$typepaymentcode = $t_data[$row]['typepaymentcode'];
		$label = $t_data[$row]['label'];
		$datesp = $t_data[$row]['datesp'];
		$dateep = $t_data[$row]['dateep'];
		$paye = $t_data[$row]['paye'];
		$account = intval($t_data[$row]['account']);
		$pdf = $t_data[$row]['pdf'];

		$lastRefSalaryQuery = $db->query('SELECT ref FROM ' . MAIN_DB_PREFIX . 'salary ORDER BY ref DESC LIMIT 1');
		if (!$lastRefSalaryQuery) {
			$errors[] = 'Erreur lors de la récupération du dernier salaire';
			$errors[] = $db->lasterror();
			continue;
		}
		$lastRefSalary = $db->fetch_object($lastRefSalaryQuery)->ref;
		$refSalary = $lastRefSalary + 1;

		$salaryIdQuery = $db->query('INSERT INTO ' . MAIN_DB_PREFIX . 'salary (ref, datep, amount, fk_typepayment, label, datesp, dateep, paye, fk_user, fk_account, fk_user_author) VALUES ("' . $refSalary . '", "' . $datep . '", "' . $amount . '", "' . $typepayment . '", "' . $label . '", "' . $datesp . '", "' . $dateep . '", "' . $paye . '", "' . $userId . '", "' . $account . '" , "' . $user->id . '")');
		if (!$salaryIdQuery) {
			$errors[] = 'Erreur lors de l\'insertion du salaire';
			$errors[] = $db->lasterror();
			continue;
		}
		$salaryId = $db->last_insert_id(MAIN_DB_PREFIX . 'salary');

		$lastRefPaymentQuery = $db->query('SELECT ref FROM ' . MAIN_DB_PREFIX . 'payment_salary ORDER BY ref DESC LIMIT 1');
		if (!$lastRefPaymentQuery) {
			$errors[] = 'Erreur lors de la récupération du dernier paiement';
			$errors[] = $db->lasterror();
			continue;
		}
		$lastRefPayment = $db->fetch_object($lastRefPaymentQuery)->ref;
		$refPayment = $lastRefPayment + 1;

		$bankInsertQuery = $db->query('INSERT INTO ' . MAIN_DB_PREFIX . 'bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type) VALUES ("' . $datep . '", "' . $datep . '", "' . $datep . '", "' . (-$amount) . '", "(SalaryPayment)", "' . $account . '", "' . $user->id . '", "' . $typepaymentcode . '")');
		if (!$bankInsertQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement en banque';
			$errors[] = $db->lasterror();
			continue;
		}
		$bank = $db->last_insert_id(MAIN_DB_PREFIX . 'bank');

		$bankUrlInsertQuery = $db->query('INSERT INTO ' . MAIN_DB_PREFIX . 'bank_url (fk_bank, url_id, url, label, type) VALUES ("' . $bank . '", "' . $salaryId . '", "/salaries/payment_salary/card.php?id=", "(paiement)", "payment_salary")');
		if (!$bankInsertQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement en banque';
			$errors[] = $db->lasterror();
			continue;
		}
		$bankUrlInsertQuery = $db->query('INSERT INTO ' . MAIN_DB_PREFIX . 'bank_url (fk_bank, url_id, url, label, type) VALUES ("' . $bank . '", "' . $userId . '", "/user/card.php?id=", "' . $userName . '", "user")');
		if (!$bankInsertQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement en banque';
			$errors[] = $db->lasterror();
			continue;
		}

		$paymentSalaryQuery = $db->query('INSERT INTO ' . MAIN_DB_PREFIX . 'payment_salary (ref, datep, amount, fk_typepayment, label, datesp, dateep, fk_user, fk_bank, fk_salary, fk_user_author) VALUES ("' . $refPayment . '", "' . $datep . '", "' . $amount . '", "' . $typepayment . '", "' . $label . '", "' . $datesp . '", "' . $dateep . '", "' . $userId . '", "' . $bank . '", "' . $salaryId . '" , "' . $user->id . '")');
		if (!$paymentSalaryQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement';
			$errors[] = $db->lasterror();
		}

		$d = DOL_DATA_ROOT . '/salaries/' . $salaryId;

		if (!is_dir($d)) dol_mkdir($d);
		dol_move($pdf, $d . '/' . basename($pdf));

		addFileIntoDatabaseIndex(
			$d,
			basename($pdf),
			basename($pdf),
			'uploaded',
			0,
			$object
		);
	}
	$db->commit();

	if (count($errors) > 0) {
		throw new Exception(implode('<br />', $errors));
	}

	print '<p>Import terminé</p>';

} catch (Exception $e) {
	if (
		!empty($filename_salary) and file_exists($dir . '/' . $filename_salary)
	) unlink($dir . '/' . $filename_salary);
	if (
		!empty($filename_zip) and file_exists($dir . '/' . $filename_zip)
	) unlink($dir . '/' . $filename_zip);
	if (
		!empty($foldername_zip) and is_dir($dir . '/' . $foldername_zip)
	) {
		$files = scandir($dir . '/' . $foldername_zip);
		foreach ($files as $file) {
			if ($file != '.' && $file != '..') {
				unlink($dir . '/' . $foldername_zip . '/' . $file);
			}
		}
		rmdir($dir . '/' . $foldername_zip);
	}

	print "<h1>Erreur lors de l'import du fichier</h1>";
	print "<p>Erreur : " . $e->getMessage() . "</p>";
}

// End of page
llxFooter();
$db->close();
