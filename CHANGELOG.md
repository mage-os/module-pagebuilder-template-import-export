# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## 1.9.0 - 2026-08-05
### Security
- Restrict PageBuilder template import to `.zip` uploads
  ([GHSA-hrj3-88v2-6wjx](https://github.com/mage-os/module-pagebuilder-template-import-export/security/advisories/GHSA-hrj3-88v2-6wjx)).
- Limit imported template assets to gallery image types (`jpg`, `jpeg`, `png`, `gif`, `webp`) and re-encode every
  asset through the image adapter, so appended data, EXIF-embedded payloads and polyglot files do not survive import.
- Reject archive entries with `..` components, absolute paths, drive-letter prefixes or NUL bytes (Zip-Slip, CWE-22).
- Cap archive entry count and total uncompressed size to limit resource exhaustion on extraction.
- Skip symbolic links when copying template assets into `pub/media`.
- Validate the caller-supplied import sub-path used by remote (Dropbox) imports before it is joined to the
  extraction directory.
- Export only assets and preview images that resolve inside `pub/media`, verified with `realpath()` so symlinked
  entries cannot escape the media root.
- Re-encode the stored template preview image in place rather than storing uploaded bytes verbatim.
- Extract each import into a unique temporary directory instead of a shared `tmp` folder.
- Delete the uploaded archive and the extraction directory once an import completes or fails.

### Changed
- Template import now fails when an archive contains an asset that is not an allowed image type. Previously any file
  type was copied into `pub/media`. Templates exported with non-image assets (for example SVG) must be re-exported
  before they can be imported.
- `TemplateManagement::storePreviewImage()` now throws a `LocalizedException` when the preview image cannot be
  processed, and removes the partially written file. It previously returned `null`.
- `CmsConverter::__construct()` takes an additional `PathValidator` argument, and
  `TemplateManagement::__construct()` takes additional `PathValidator` and `LoggerInterface` arguments. Classes that
  extend either and call `parent::__construct()` positionally must be updated.

## 1.8.1 - 2026-05-17
### Fixed
- Declare `: int` return type on CLI commands for compatibility with Magento 2.4.9 and Mage-OS 3.

## 1.8.0 - 2026-04-21
### Fixed
- PHP 8.4 and PHP 8.5 compatibility.
- Guard `trim()` against `file_get_contents()` returning `false` in `substituteAdminhtmlStaticUrl`.
- Guard `scandir()` and `file_get_contents()` false returns in `importTemplateChildren`.
- Null-safe access to `parse_url()` result keys in `TemplateManagement::doSecurityScanForTemplate` and `CmsConverter::substituteSiteUrls`.
- Null-safe `explode()` offset access in `CmsConverter::convert` when parsing pagebuilder media tokens.
- `ModuleConfig::getDropboxCredentials()` no longer passes non-string values to `unserialize()` and always returns an array.
- `ModuleConfig::getDropboxAccountCredentialsByAppKey()` no longer iterates non-array credentials.
- `ApiKeySerialized::beforeSave()` and `afterSave()` no longer iterate/unserialize non-array/non-string values.
- Removed invalid `return` statements from console command constructors (`ImportTemplate`, `UpdateRemoteTemplateList`).
- Replaced erroneous `PHPUnit\Util\Exception` usage with `LocalizedException` in the remote import controller.

### Updated
- Widened `composer.json` PHP constraint to support PHP 8.1 through 8.5.

## 1.7.0
## Updated
- Add security check on external files imported through template.
- Replace adminhtml and frontend urls files included through pagebuilder widget preview module or other solutions.
### Fixed
- Fix undefined variable $children in importTemplateChildren when no files match the pattern
- Add return type hints to SearchResultInterface methods in Grid/Collection

## 1.6.2
## Updated
- Add "repeatable_" prefixed widget fields management downloading media files during export.

## 1.6.1
- Re-add custom ACL for import/export template functionalities fixing issue #7

## 1.6.0
- Fix import issue for missing folders on pub/media, fix issues #7, #6, #5, #4, #3

## 1.5.3
### Fixed
- Fix issue #1 for error generated saving configurations with empty dropbox settings at first install.

## 1.5.2
### Fixed
- Fix error for template sync for credentials containing refresh_token 

## 1.5.0
### Fixed
- Fix Ui/Ux for credentials config and documentation related to it

## 1.4.1
### Fixed
- Fix error on config mapping for remote templates async save

## 1.4.0
### Updated
- Add remote templates synchronization by cron on configuration save

## 1.1.0
### Updated
- Manage remote dropbox storage sync through webhooks using listFolder API cursors. See: https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder-get_latest_cursor


## 1.1.0
### Updated
- Manage remote dropbox storage sync through webhooks using listFolder API cursors. See: https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder-get_latest_cursor

## 1.0.0
### Updated
- Add adminhtml ui management for remote templates import from dropbox

## 0.2.3
### Updated
- Export template now consider also annidated children cms blocks

## 0.2.2
### Updated
- Centralize export template to archive file into one single method

## 0.2.1
### Fixed
- Fixed module composer.json file adding missing version
### Updated 
- Export template method grouped inside a main export method specified by service contract

## 0.2.0
### Added
- First beta release
- Template management Model and import/export console commands are now available

## 0.1.0
### Added
- First Commit
