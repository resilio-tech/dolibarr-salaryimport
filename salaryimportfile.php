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
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;


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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/salaries/class/salary.class.php';

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
if (! $user->hasRight('salaryimport', 'myobject', 'read')) {
	accessforbidden();
}
restrictedArea($user, 'salaryimport', 0, 'salaryimport_myobject', 'myobject', '', 'rowid');
if (empty($user->admin)) {
	accessforbidden('Must be admin');
}


/*
 * Actions
 */

// None

require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
require_once PHPEXCELNEW_PATH.'Reader/Xlsx.php';

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);
$object = new Salary($db);

llxHeader("", $langs->trans("SalaryImportArea"));

$file = $_FILES['file'];
$zip = $_FILES['zip'];

$filename_salary = '';
$filename_zip = '';
$foldername_zip = '';
$pdfs = array();
$dir = DOL_DATA_ROOT.'/salaryimport';
if (!is_dir($dir)) dol_mkdir($dir);

try {
	if ($file['error'] != 0) throw new Exception('Erreur lors de l\'envoi du fichier de salaire');

	$filename_salary = $file['name'];
	$ext = substr($filename_salary, strrpos($filename_salary, '.') + 1);
	$ext = strtolower($ext);
	if ($ext != 'xlsx') throw new Exception('Le fichier de salaire doit être au format xlsx');

	if (!dol_move_uploaded_file($file['tmp_name'], $dir.'/'.$filename_salary, 1, 0 ,0)) throw new Exception('Erreur lors de l\'envoi du fichier de salaire');

	if ($zip && $zip['size'] > 0) {
		if ($zip['error'] != 0) throw new Exception('Erreur lors de l\'envoi du fichier zip de PDF');

		$filename_zip = $zip['name'];
		$ext = substr($filename_zip, strrpos($filename_zip, '.') + 1);
		$ext = strtolower($ext);
		if (
			$ext != 'zip'
		) throw new Exception('Le fichier zip de PDF doit être dans un format zip ou 7z');

		if (!dol_move_uploaded_file($zip['tmp_name'], $dir.'/'.$filename_zip, 1, 0 ,0)) throw new Exception('Erreur lors de l\'envoi du fichier zip de PDF');

		$zip = new ZipArchive();
		$foldername_zip = substr($filename_zip, 0, strrpos($filename_zip, '.'));
		if ($zip->open($dir.'/'.$filename_zip) === true) {
			$zip->extractTo($dir.'/'.$foldername_zip);
			$zip->close();

			$files = scandir($dir.'/'.$foldername_zip);
			foreach ($files as $file) {
				if ($file != '.' && $file != '..') {
					$ext = substr($file, strrpos($file, '.') + 1);
					$ext = strtolower($ext);
					if ($ext == 'pdf') {
						$n = substr(strtolower($file), 0, strrpos($file, '.'));
						$links = explode('_', $n);
						$pdfs[] = array(
							'filename' => $file,
							'path' => $dir.'/'.$foldername_zip.'/'.$file,
							'links' => $links
						);
					}
				}
			}
		}
		else {
			throw new Exception('Erreur lors de l\'extraction du fichier zip de PDF');
		}
	}

	is_readable($dir.'/'.$filename_salary) or throw new Exception('Erreur lors de la lecture du fichier de salaire');

	$reader = new Xlsx();
	$reader->canRead($dir.'/'.$filename_salary) or throw new Exception('Erreur lors de la lecture du fichier de salaire');
	$spreadsheet = $reader->load($dir.'/'.$filename_salary);

	$sheet = $spreadsheet->getActiveSheet();

	$rowCount = $sheet->getHighestRow();
	$countColumns = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

	$headers = array();
	for ($col = 1; $col <= $countColumns; $col++) {
		$headers[$col] = $sheet->getCellByColumnAndRow($col, 1)->getValue();
	}

	$lines = array();
	for ($row = 2; $row <= $rowCount; $row++) {
		$line = array();
		for ($col = 1; $col <= $countColumns; $col++) {
			$line[$headers[$col]] = $sheet->getCellByColumnAndRow($col, $row)->getValue();
		}
		$lines[] = $line;
	}

	if (count($lines) == 0) throw new Exception('Aucune ligne trouvée dans le fichier');

	$TData = array();
	$errors = array();

	for ($row = 0; $row < count($lines); $row++) {
		$TData[$row] = array();

		$firstname = $lines[$row]['Prénom'];
		$lastname = $lines[$row]['Nom'];
		if (empty($firstname) || empty($lastname)) {
			$errors[] = 'Prénom ou nom vide à la ligne '.($row + 1);
		} else {
			$userId = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'user WHERE lastname = "' . $db->escape($lastname) . '" AND firstname = "' . $db->escape($firstname) . '"');
			if ($db->num_rows($userId) == 0) {
				$errors[] = 'Utilisateur non trouvé à la ligne ' . ($row + 1);
			} else {
				$u = $db->fetch_object($userId);
				$userId = $u->rowid;
				$TData[$row]['userId'] = $userId;
				$TData[$row]['userName'] = $firstname . ' ' . $lastname;
			}
		}

		function removeAccents($string) {
			return strtolower(
				trim(
					preg_replace(
						'~[^0-9a-z]+~i',
						'-',
						preg_replace(
							'~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i',
							'$1',
							htmlentities($string, ENT_QUOTES, 'UTF-8')
						)
					),
					' '
				)
			);
		}

		$TData[$row]['pdf'] = '';
		foreach ($pdfs as $pdf) {
			$found = false;
			if (
				(
					in_array(removeAccents($firstname), array_map(function ($s) {return removeAccents($s);}, $pdf['links']))
					&& in_array(removeAccents($lastname), array_map(function ($s) {return removeAccents($s);}, $pdf['links']))
				)
				|| (in_array(strtolower($firstname.' '.$lastname), $pdf['links']))
			) {
				$found = true;
			}
			if ($found) {
				$TData[$row]['pdf'] = $pdf['path'];
				break;
			}
		}
		if (empty($TData[$row]['pdf'])) {
			$lines[$row]['Fichier PDF joint'] = 'Aucun';
		} else {
			$lines[$row]['Fichier PDF joint'] = strrpos($TData[$row]['pdf'], '/') !== false
				? substr($TData[$row]['pdf'], strrpos($TData[$row]['pdf'], '/') + 1)
				: $TData[$row]['pdf'];
		}


		$datep = $lines[$row]['Date de paiement'];
		if (empty($datep)) {
			$errors[] = 'Date de paiement vide à la ligne '.($row + 1);
		}
		else {
			if ($datep === false) {
				$errors[] = 'Date de paiement ('. $datep .') invalide à la ligne '.($row + 1);
			}
			else {
				$TData[$row]['datep'] = date('Y-m-d', ($datep - 25569) * 86400);
				$lines[$row]['Date de paiement'] = date('d/m/Y', ($datep - 25569) * 86400);
			}
		}

		$amount = $lines[$row]['Montant'];
		if (empty($amount) && $amount !== '0' && $amount !== 0) {
			$errors[] = 'Montant vide à la ligne '.($row + 1);
		}
		else {
			$amount = str_replace(',', '.', $amount);
			$amount = floatval($amount);
			if ($amount === false) {
				$errors[] = 'Montant invalide à la ligne '.($row + 1);
			}
			else {
				$TData[$row]['amount'] = $amount;
			}
		}

		$label = $lines[$row]['Libellé'];
		if (empty($label)) {
			$errors[] = 'Libellé vide à la ligne '.($row + 1);
		}
		else {
			$TData[$row]['label'] = $label;
		}

		$datesp = $lines[$row]['Date de début'];
		if (empty($datesp)) {
			$errors[] = 'Date de début vide à la ligne '.($row + 1);
		}
		else {
			if ($datesp === false) {
				$errors[] = 'Date de début ('. $datesp .') invalide à la ligne '.($row + 1);
			}
			else {
				$TData[$row]['datesp'] = date('Y-m-d', ($datesp - 25569) * 86400);
				$lines[$row]['Date de début'] = date('d/m/Y', ($datesp - 25569) * 86400);
			}
		}

		$dateep = $lines[$row]['Date de fin'];
		if (empty($dateep)) {
			$errors[] = 'Date de fin ('. $dateep .') vide à la ligne '.($row + 1);
		}
		else {
			if ($dateep === false) {
				$errors[] = 'Date de fin invalide à la ligne '.($row + 1);
			}
			else {
				$TData[$row]['dateep'] = date('Y-m-d', ($dateep - 25569) * 86400);
				$lines[$row]['Date de fin'] = date('d/m/Y', ($dateep - 25569) * 86400);
			}
		}

		$typepayment = $lines[$row]['Type de paiement'];
		if (empty($typepayment)) {
			$errors[] = 'Type de paiement vide à la ligne '.($row + 1);
		}
		else {
			$paymentId = $db->query('SELECT id, libelle FROM ' . MAIN_DB_PREFIX . 'c_paiement WHERE code = "' . $db->escape($typepayment) . '"');
			if ($db->num_rows($paymentId) == 0) {
				$errors[] = 'Type de paiement non trouvé à la ligne ' . ($row + 1);
			} else {
				$payment = $db->fetch_object($paymentId);
				$lines[$row]['Type de paiement'] = $payment->libelle;
				$TData[$row]['typepayment'] = $payment->id;
				$TData[$row]['typepaymentcode'] = $typepayment;
			}
		}

		$paye = $lines[$row]['Payé'];
		if (empty($paye)) {
			$errors[] = 'Payé vide à la ligne '.($row + 1);
		}
		else {
			if ($paye !== 'oui' && $paye !== 'non') {
				$errors[] = 'Payé invalide à la ligne '.($row + 1);
			}
			else {
				$TData[$row]['paye'] = $paye === 'oui' ? 1 : 0;
			}
		}

		$account = $lines[$row]['Compte bancaire'];
		if (empty($account)) {
			$errors[] = 'Compte bancaire vide à la ligne '.($row + 1);
		}
		else {
			$account = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'bank_account WHERE ref = "'.$db->escape($account).'" OR label = "'.$db->escape($account).'"');
			if ($db->num_rows($account) == 0) {
				$errors[] = 'Compte bancaire non trouvé à la ligne '.($row + 1);
			}
			else {
				$account = $db->fetch_object($account)->rowid;
				$TData[$row]['account'] = $account;
			}
		}
	}

	if (count($errors) > 0) {
		throw new Exception(implode('<br />', $errors));
	}

	array_push($headers, 'Fichier PDF joint');

	print '<table class="noborder" width="100%">';
	print '<tr class="liste_titre">';
	for ($col = 1; $col <= count($headers); $col++) {
		print '<td>'. $headers[$col] .'</td>';
	}
	print '</tr>';
	for ($row = 0; $row < count($TData); $row++) {
		print '<tr class="oddeven">';
		for ($col = 1; $col <= $countColumns + 1; $col++) {
			print '<td>'.$lines[$row][$headers[$col]].'</td>';
		}
		print '</tr>';
	}
	print '</table>';

	$errors = array();
/*
	for ($row = 0; $row < count($TData); $row++) {
		$userId = $TData[$row]['userId'];
		$userName = $TData[$row]['userName'];
		$datep = $TData[$row]['datep'];
		$amount = $TData[$row]['amount'];
		$typepayment = $TData[$row]['typepayment'];
		$typepaymentcode = $TData[$row]['typepaymentcode'];
		$label = $TData[$row]['label'];
		$datesp = $TData[$row]['datesp'];
		$dateep = $TData[$row]['dateep'];
		$paye = $TData[$row]['paye'];
		$account = $TData[$row]['account'];
		$pdf = $TData[$row]['pdf'];

		$lastRefSalaryQuery = $db->query('SELECT ref FROM '.MAIN_DB_PREFIX.'salary ORDER BY ref DESC LIMIT 1');
		if (!$lastRefSalaryQuery) {
			$errors[] = 'Erreur lors de la récupération du dernier salaire';
			$errors[] = $db->lasterror();
			continue;
		}
		$lastRefSalary = $db->fetch_object($lastRefSalaryQuery)->ref;
		$refSalary = $lastRefSalary + 1;

		$salaryIdQuery = $db->query('INSERT INTO '.MAIN_DB_PREFIX.'salary (ref, datep, amount, fk_typepayment, label, datesp, dateep, paye, fk_user, fk_account, fk_user_author) VALUES ("'.$refSalary.'", "'.$datep.'", "'.$amount.'", "'.$typepayment.'", "'.$label.'", "'.$datesp.'", "'.$dateep.'", "'.$paye.'", "'.$userId.'", "'.$account.'" , "'.$user->id.'")');
		if (!$salaryIdQuery) {
			$errors[] = 'Erreur lors de l\'insertion du salaire';
			$errors[] = $db->lasterror();
			continue;
		}
		$salaryId = $db->last_insert_id(MAIN_DB_PREFIX.'salary');

		$lastRefPaymentQuery = $db->query('SELECT ref FROM '.MAIN_DB_PREFIX.'payment_salary ORDER BY ref DESC LIMIT 1');
		if (!$lastRefPaymentQuery) {
			$errors[] = 'Erreur lors de la récupération du dernier paiement';
			$errors[] = $db->lasterror();
			continue;
		}
		$lastRefPayment = $db->fetch_object($lastRefPaymentQuery)->ref;
		$refPayment = $lastRefPayment + 1;

		$bankInsertQuery = $db->query('INSERT INTO '.MAIN_DB_PREFIX.'bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type) VALUES ("'.$datep.'", "'.$datep.'", "'.$datep.'", "'.(-$amount).'", "(SalaryPayment)", "'.$account.'", "'.$user->id.'", "'.$typepaymentcode.'")');
		if (!$bankInsertQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement en banque';
			$errors[] = $db->lasterror();
			continue;
		}
		$bank = $db->last_insert_id(MAIN_DB_PREFIX.'bank');

		$bankUrlInsertQuery = $db->query('INSERT INTO '.MAIN_DB_PREFIX.'bank_url (fk_bank, url_id, url, label, type) VALUES ("'.$bank.'", "'.$salaryId.'", "/salaries/payment_salary/card.php?id=", "(paiement)", "payment_salary")');
		if (!$bankInsertQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement en banque';
			$errors[] = $db->lasterror();
			continue;
		}
		$bankUrlInsertQuery = $db->query('INSERT INTO '.MAIN_DB_PREFIX.'bank_url (fk_bank, url_id, url, label, type) VALUES ("'.$bank.'", "'.$userId.'", "/user/card.php?id=", "'.$userName.'", "user")');
		if (!$bankInsertQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement en banque';
			$errors[] = $db->lasterror();
			continue;
		}

		$paymentSalaryQuery = $db->query('INSERT INTO '.MAIN_DB_PREFIX.'payment_salary (ref, datep, amount, fk_typepayment, label, datesp, dateep, fk_user, fk_bank, fk_salary, fk_user_author) VALUES ("'.$refPayment.'", "'.$datep.'", "'.$amount.'", "'.$typepayment.'", "'.$label.'", "'.$datesp.'", "'.$dateep.'", "'.$userId.'", "'.$bank.'", "'.$salaryId.'" , "'.$user->id.'")');
		if (!$paymentSalaryQuery) {
			$errors[] = 'Erreur lors de l\'insertion du paiement';
			$errors[] = $db->lasterror();
		}

		$d = DOL_DATA_ROOT.'/salaries/'.$salaryId;

		if (!is_dir($d)) dol_mkdir($d);
		dol_move($pdf, $d.'/'.basename($pdf));

		addFileIntoDatabaseIndex(
			$d,
			basename($pdf),
			basename($pdf),
			'uploaded',
			0,
			$object
		);
	}
*/
	print '<form method="POST" action="/custom/salaryimport/salaryimportconfirm.php" enctype="multipart/form-data">';
	for ($row = 0; $row < count($TData); $row++) {
		print '<input type="hidden" name="t_data['.$row.'][userId]" value="'.$TData[$row]['userId'].'">';
		print '<input type="hidden" name="t_data['.$row.'][userName]" value="'.$TData[$row]['userName'].'">';
		print '<input type="hidden" name="t_data['.$row.'][datep]" value="'.$TData[$row]['datep'].'">';
		print '<input type="hidden" name="t_data['.$row.'][amount]" value="'.$TData[$row]['amount'].'">';
		print '<input type="hidden" name="t_data['.$row.'][typepayment]" value="'.$TData[$row]['typepayment'].'">';
		print '<input type="hidden" name="t_data['.$row.'][typepaymentcode]" value="'.$TData[$row]['typepaymentcode'].'">';
		print '<input type="hidden" name="t_data['.$row.'][label]" value="'.$TData[$row]['label'].'">';
		print '<input type="hidden" name="t_data['.$row.'][datesp]" value="'.$TData[$row]['datesp'].'">';
		print '<input type="hidden" name="t_data['.$row.'][dateep]" value="'.$TData[$row]['dateep'].'">';
		print '<input type="hidden" name="t_data['.$row.'][paye]" value="'.$TData[$row]['paye'].'">';
		print '<input type="hidden" name="t_data['.$row.'][account]" value="'.$TData[$row]['account'].'">';
		print '<input type="hidden" name="t_data['.$row.'][pdf]" value="'.$TData[$row]['pdf'].'">';
	}
	print '<input type="submit" value="Envoyer">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="confirm">';
	print '</form>';
} catch (Exception $e) {
	if (
		!empty($filename_salary) and file_exists($dir.'/'.$filename_salary)
	) unlink($dir.'/'.$filename_salary);
	if (
		!empty($filename_zip) and file_exists($dir.'/'.$filename_zip)
	) unlink($dir.'/'.$filename_zip);
	if (
		!empty($foldername_zip) and is_dir($dir.'/'.$foldername_zip)
	) {
		$files = scandir($dir.'/'.$foldername_zip);
		foreach ($files as $file) {
			if ($file != '.' && $file != '..') {
				unlink($dir.'/'.$foldername_zip.'/'.$file);
			}
		}
		rmdir($dir.'/'.$foldername_zip);
	}

	print "<h1>Erreur lors de l'import du fichier</h1>";
	print "<p>Erreur : ".$e->getMessage()."</p>";
}

// End of page
llxFooter();
$db->close();
