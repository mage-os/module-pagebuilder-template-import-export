# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## 1.6.3
## Updated
- Add "repeatable_" prefixed widget fields management downloading media files during export.

## 1.6.2
## Updated
- Move old configurations to new path, add default configurations.

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
