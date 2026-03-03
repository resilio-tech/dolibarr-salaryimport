#!/usr/bin/env python3
"""
Script to create a test XLSX file with English headers for salary import
"""
from openpyxl import Workbook

wb = Workbook()
ws = wb.active

# English headers
headers = [
    'First name',
    'Last name',
    'Payment date',
    'Amount',
    'Label',
    'Start date',
    'End date',
    'Payment type',
    'Paid',
    'Bank account'
]

# Write headers
for col, header in enumerate(headers, 1):
    ws.cell(row=1, column=col, value=header)

# Test data
data = [
    ['John', 'Doe', '2026-01-15', 4500.00, 'Salary January 2026', '2026-01-01', '2026-01-31', 'VIR', 'yes', 'POSTE_CH'],
    ['Jane', 'Smith', '2026-01-15', 4200.00, 'Salary January 2026', '2026-01-01', '2026-01-31', 'VIR', 'yes', 'POSTE_CH'],
]

# Write data
for row_idx, row_data in enumerate(data, 2):
    for col_idx, value in enumerate(row_data, 1):
        ws.cell(row=row_idx, column=col_idx, value=value)

# Save
output_path = '/home/slordef/work/dolibarr/htdocs/custom/salaryimport/test/salaires_test_english.xlsx'
wb.save(output_path)
print(f"Created: {output_path}")
