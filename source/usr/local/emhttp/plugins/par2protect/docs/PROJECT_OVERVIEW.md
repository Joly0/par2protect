# PAR2Protect Plugin Overview

This document provides a map of the PAR2Protect plugin codebase, outlining the purpose of key directories and files.

## Root Directory (`/`)

*   **`PAR2Protect.page`**: Main Unraid plugin page file (defines menu entry, loads main content).
*   **`PAR2ProtectList.page`**: Unraid page file for the "Protected Files" list view.
*   **`PAR2ProtectMain.page`**: Unraid page file for the main Dashboard view.
*   **`PAR2ProtectSettings.page`**: Unraid page file for the Settings view.
*   **`README.md`**: Project README file.

## `api/` & `api/v1/`

Handles the backend API requests from the frontend UI.

*   **`api/index.php`**: Main entry point for all API calls (likely includes `api/v1/index.php`).
*   **`api/v1/index.php`**: Entry point for v1 API, sets up routing.
*   **`api/v1/router.php`**: (`Router` class) Handles routing API requests to the correct endpoint method based on URL and HTTP method.
*   **`api/v1/routes.php`**: Defines the specific API routes and maps them to endpoint classes/methods.
*   **`api/v1/endpoints/`**: Contains classes handling specific API resource requests. Each endpoint typically depends on one or more services from the `services/` directory.
    *   **`DebugEndpoint.php`**: (`DebugEndpoint` class) Provides debugging-related API actions.
    *   **`EventsEndpoint.php`**: (`EventsEndpoint` class) Handles Server-Sent Events (SSE) for real-time updates to the frontend.
    *   **`LogEndpoint.php`**: (`LogEndpoint` class) Provides access to activity logs and potentially log file downloads/clearing.
    *   **`ProtectionEndpoint.php`**: (`ProtectionEndpoint` class) Handles requests related to managing protected items (get all, get status, protect, remove, reprotect, get files).
    *   **`QueueEndpoint.php`**: (`QueueEndpoint` class) Manages interactions with the operation queue (get all, get status, add, cancel, cleanup, kill stuck).
    *   **`SettingsEndpoint.php`**: (`SettingsEndpoint` class) Handles retrieving and updating plugin configuration.
    *   **`StatusEndpoint.php`**: (`StatusEndpoint` class) Provides overall plugin status, including protection stats, queue status, system info (PAR2 version, DB status, disk usage), and recent activity.
    *   **`VerificationEndpoint.php`**: (`VerificationEndpoint` class) Handles requests related to verification and repair operations.

## `backup_merged_files/`

Contains backups of JS/CSS files that were consolidated during previous refactoring.

*   **`dashboard/`**: Backups from `features/dashboard/`.
*   **`list/`**: Backups from `features/list/`.
*   **`protection/`**: Backup of old `MetadataManager.php`.
*   **`verification/`**: Backup of old `MetadataManager.php`.

## `core/`

Contains fundamental classes and utilities used throughout the plugin. (Refactored to use DI).

*   **`bootstrap.php`**: Initializes the plugin environment, sets up autoloading, error handling, and the dependency injection container. Registers all core services and dependencies. Provides `get_container()`.
*   **`Cache.php`**: (`Cache` class) Filesystem-based caching mechanism (uses `/tmp`). Depends on `Logger`.
*   **`Config.php`**: (`Config` class) Manages loading, accessing, and saving plugin configuration from `config.json`. Handles schema defaults and legacy `.cfg` migration. Depends on `Logger`.
*   **`Container.php`**: (`Container` class) Simple dependency injection container used to manage service instantiation.
*   **`Database.php`**: (`Database` class) Wrapper for the main SQLite database (`par2protect.db`) handling persistent data (protected items, history, metadata). Includes retry logic. Depends on `Logger`, `Config`.
*   **`EventSystem.php`**: (`EventSystem` class) Manages a simple event queue using a dedicated SQLite database (`events.db`) for real-time updates (used by SSE). Depends on `Logger`.
*   **`Logger.php`**: (`Logger` class) Handles logging to files (`/tmp` and permanent location), Unraid syslog, and an internal activity log (JSON). Manages log rotation and backup. Configured via `bootstrap.php` using `Config`.
*   **`MetadataManager.php`**: (`MetadataManager` class) Consolidated class for storing, retrieving, verifying, and restoring file metadata (permissions, owner, group, mtime, extended attributes). Also includes size calculation logic. Depends on `Database`, `Logger`. Uses `ReadsFileSystemMetadata` trait.
*   **`QueueDatabase.php`**: (`QueueDatabase` class) Wrapper for the queue SQLite database (`queue.db` in `/tmp`). Handles queue-specific DB operations. Includes retry logic. Depends on `Logger`, `Config`.
*   **`Response.php`**: (`Response` class) Static helper class for generating formatted HTTP responses (JSON, HTML, File, Redirect).
*   **`commands/`**: Classes for building `par2` command-line strings safely. Depend on `Config`, `Logger`. Use `AddsPar2ResourceLimits` trait.
    *   **`Par2CreateCommandBuilder.php`**
    *   **`Par2RepairCommandBuilder.php`**
    *   **`Par2VerifyCommandBuilder.php`**
*   **`exceptions/`**: Custom exception classes.
    *   **`ApiException.php`**
    *   **`DatabaseException.php`**
    *   **`Par2ExecutionException.php`**
*   **`traits/`**: Reusable code snippets for classes.
    *   **`AddsPar2ResourceLimits.php`**: Adds logic to apply CPU/IO limits (`ionice`, `cpulimit`) to commands.
    *   **`ReadsFileSystemMetadata.php`**: Provides `getFileMetadata()` method to read owner, group, permissions, mtime, and extended attributes.

## `docs/`

Contains documentation files, help text, etc.

## `features/`

Contains code specific to different UI sections (Dashboard, List, Settings).

*   **`dashboard/`**: Files for the main dashboard UI.
    *   **`DashboardPage.php`**: Controller class, includes `dashboard.php`.
    *   **`dashboard.php`**: Main HTML template for the dashboard.
    *   **`dashboard.js`**: Frontend JavaScript logic for the dashboard.
    *   **`dashboard.css`**: Styles for the dashboard.
    *   **`verification-options-dialog.php`**: HTML template for the verification options dialog.
*   **`list/`**: Files for the "Protected Files" list UI.
    *   **`ListPage.php`**: Controller class, includes `list.php`.
    *   **`list.php`**: Main HTML template for the list view.
    *   **`list.js`**: Frontend JavaScript logic for the list view.
    *   **`list.css`**: Styles for the list view.
    *   **`verification-options-dialog.php`**: HTML template for the verification options dialog.
*   **`settings/`**: Files for the Settings UI.
    *   **`SettingsPage.php`**: Controller class, includes `settings.php`, handles form processing.
    *   **`settings.php`**: Main HTML template for the settings form.
    *   **`settings.js`**: Frontend JavaScript logic for the settings page.
    *   **`settings.css`**: Styles for the settings page.

## `scripts/`

Contains standalone PHP scripts for background tasks, cron jobs, setup, etc. (Refactored to use container via `bootstrap.php`).

*   **`backup_logs.php`**: Performs the log backup operation (called by cron).
*   **`check_schedule.php`**: Checks configured schedule and adds verification tasks to the queue.
*   **`cleanup_events.php`**: Cleans old events from the event database (called by cron).
*   **`init_db.php`**: Initializes the main database schema.
*   **`init_tmp_dirs.php`**: Creates necessary directories in `/tmp` on startup.
*   **`monitor.php`**: Script for monitoring plugin health (checks DB, queue). Can optionally attempt fixes.
*   **`process_queue.php`**: The main background worker script that processes operations (protect, verify, repair, remove) from the queue. Interacts heavily with `Protection` and `Verification` services.
*   **`reset_db.php`**: Deletes and reinitializes the main database (requires `--force`).
*   **`save_settings.php`**: Handles saving settings submitted from the Unraid UI (called by Unraid).
*   **`setup_events_cleanup_cron.php`**: Adds/updates the cron job for `cleanup_events.php`.
*   **`setup_log_backup_cron.php`**: Adds/updates the cron job for `backup_logs.php`.
*   **`update_menu_placement.php`**: Updates a `.cfg` file based on JSON config for Unraid menu integration.
*   **`update_system_information_page.php`**: Creates/removes a `.page` file based on config for Unraid menu integration.
*   **`tests/`**: Contains test scripts (refactored to use container).

## `services/`

Contains the core business logic services.

*   **`protection.php`**: Wrapper class extending `Protection\Protection`.
*   **`queue.php`**: (`Queue` class) Manages the operation queue (add, get status, cancel, etc.). Depends on `Database`, `QueueDatabase`, `Logger`, `Config`.
*   **`verification.php`**: Wrapper class extending `Verification\Verification`.
*   **`protection/`**: Components related to file protection.
    *   **`Protection.php`**: Main service orchestrating protection and removal. Depends on `Logger`, `Config`, `Cache`, `Database`, `ProtectionRepository`, `ProtectionOperations`, `MetadataManager`, `FormatHelper`.
    *   **`ProtectionOperations.php`**: Handles execution of `par2 create` commands and PAR2 file/directory deletion. Depends on `Logger`, `Config`, `FormatHelper`, `EventSystem`, `Par2CreateCommandBuilder`. Contains `removeDirectoryRecursive`.
    *   **`ProtectionRepository.php`**: Handles database operations for `protected_items` table. Depends on `Database`, `Logger`, `Cache`.
    *   **`helpers/FormatHelper.php`**: Provides utility functions for formatting (e.g., category names, sizes).
*   **`verification/`**: Components related to file verification and repair.
    *   **`Verification.php`**: Main service orchestrating verification and repair. Depends on `Logger`, `Config`, `Cache`, `Database`, `VerificationRepository`, `VerificationOperations`, `MetadataManager`.
    *   **`VerificationOperations.php`**: Handles execution of `par2 verify` and `par2 repair` commands. Depends on `Logger`, `Config`, `Par2VerifyCommandBuilder`, `Par2RepairCommandBuilder`.
    *   **`VerificationRepository.php`**: Handles database operations for verification status and history. Depends on `Database`, `Logger`, `Cache`.

## `shared/`

Contains assets and components shared across different features.

*   **`components/`**: Reusable UI components (PHP/JS/CSS).
    *   **`help-text.php`**: PHP for rendering help text elements.
    *   **`FileTreePicker/`**: Component for selecting files/folders.
*   **`css/`**: Shared CSS files (`common.css`, `sweetalert-dark-fix.css`).
*   **`js/`**: Shared JavaScript files (`common.js`, `help-text.js`, `logger.js`, `queue-manager.js`).