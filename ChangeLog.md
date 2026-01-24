# CHANGELOG SALARYIMPORT FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.16.0 (2026-01-24)

### Changed
- Cleaned legacy MYOBJECT template code
- Simplified setup page (no configuration needed)
- Updated permission to use translation key
- Removed unused backport directory and ajax files

### Added
- Translated permission labels (FR/EN)
- Workflow creates PR for version bump after release

## 1.15 (2024-07-22)

### Fixed
- PhpSpreadsheet open_basedir restriction patch

## 1.14 (2024-07-22)

### Changed
- Import file directory now uses Dolibarr directory

## 1.13 (2024-07-22)

### Fixed
- Minor fixes

### Added
- Improved open_basedir compatibility

## 1.12 (2024-07-22)

### Added
- GitHub workflow for automatic build

### Fixed
- Header parser may be null check

### Changed
- Added consecutive PDF name & overlap handling

## 1.11 (2024-07-22)

### Fixed
- Entity handling correction
- Bank URL link fix
- PDF matching improvements

## 1.10 (2024-07-22)

### Changed
- Total refactoring to separate by classes
- Added unit tests
- Removed duplicate functions
- Improved system architecture

## 1.9 (2024-07-22)

### Fixed
- Set by current entity

## 1.8 (2024-07-22)

### Fixed
- Import with 0 on amount
- Accents insensitive matching

## 1.7 (2024-07-22)

### Added
- Verification page

## 1.6 (2024-07-22)

### Fixed
- Check for names with space between firstname and lastname

## 1.0 (2024-01-23)

### Added
- Initial version
- Excel/CSV salary import
- User matching
- PDF payslip matching
