# Salary Import for Dolibarr

Module for importing salaries from Excel files with optional PDF payslip matching.

## Features

- Import salaries from XLSX files
- Optional ZIP file with PDF payslips for automatic matching
- User matching by firstname/lastname
- Preview before import
- Bank account assignment
- Payment type configuration

## Requirements

- Dolibarr 11.0 or later
- PHP 7.0 or later
- PhpSpreadsheet (included in Dolibarr)

## Installation

1. Download the module and extract to `htdocs/custom/salaryimport`
2. Enable the module in Dolibarr: Setup > Modules > Other
3. Grant permissions to users who need access

## Usage

1. Go to Accounting > Employees > Import Salaries
2. Upload an XLSX file with salary data
3. Optionally upload a ZIP file containing PDF payslips
4. Review the preview and confirm import

### Excel File Format

The Excel file should contain columns for:
- Employee name (firstname, lastname)
- Payment date
- Amount
- Payment type
- Start/end period dates
- Bank account

## License

GPLv3 or (at your option) any later version. See file COPYING for more information.
