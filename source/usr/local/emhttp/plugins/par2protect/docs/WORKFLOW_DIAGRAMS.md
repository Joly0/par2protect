# PAR2Protect Workflow Diagrams

This document illustrates the execution flow for key user actions within the PAR2Protect plugin using Mermaid sequence diagrams.

## 1. Protect Files/Folders (from Dashboard)

This diagram shows the sequence when a user adds folders/files via the dashboard protection dialog and clicks "Start Protection".

```mermaid
sequenceDiagram
    participant User
    participant DashboardUI (dashboard.js)
    participant QueueMgr (queue-manager.js)
    participant API (QueueEndpoint.php)
    participant QueueSvc (queue.php)
    participant QueueDB (queue.db)
    participant Processor (process_queue.php)
    participant ProtectionSvc (Protection.php)
    participant ProtectionOps (ProtectionOperations.php)
    participant ProtectionRepo (ProtectionRepository.php)
    participant MetadataMgr (MetadataManager.php)
    participant PAR2CLI
    participant EventSys (EventSystem.php)

    User->>+DashboardUI: Click "Start Protection"
    DashboardUI->>DashboardUI: startProtection()
    DashboardUI->>+QueueMgr: addToQueue('protect', params)
    QueueMgr->>+API: POST /api/v1/queue (type='protect', params)
    API->>+QueueSvc: addOperation('protect', params)
    QueueSvc->>+QueueDB: INSERT INTO operation_queue (status='pending')
    QueueDB-->>-QueueSvc: operation_id
    QueueSvc-->>-API: {success: true, operation_id}
    API-->>-QueueMgr: {success: true, operation_id}
    QueueMgr->>DashboardUI: successCallback()
    QueueMgr->>DashboardUI: showOperationImmediately()
    DashboardUI-->>-User: Show "Pending" in UI
    deactivate QueueMgr

    Note over Processor: Periodically checks queue...
    Processor->>+QueueDB: SELECT ... WHERE status='pending' LIMIT 1
    QueueDB-->>-Processor: 'protect' operation data (params)
    Processor->>Processor: Mark operation 'processing' in QueueDB
    Processor->>+ProtectionSvc: protect(params)
    ProtectionSvc->>+ProtectionOps: createPar2Files(...)
    ProtectionOps->>PAR2CLI: Executes `par2 create` command
    PAR2CLI-->>-ProtectionOps: Success/Failure
    ProtectionOps-->>-ProtectionSvc: par2Path / Exception
    ProtectionSvc->>+MetadataMgr: storeMetadata(...)
    MetadataMgr-->>-ProtectionSvc: Success/Failure
    ProtectionSvc->>+ProtectionRepo: addProtectedItem(...)
    ProtectionRepo-->>-ProtectionSvc: protectedItemId
    ProtectionSvc->>+MetadataMgr: getDataSize(...) / getPar2Size(...)
    MetadataMgr-->>-ProtectionSvc: Sizes
    ProtectionSvc->>+ProtectionRepo: updateSizeInfo(...)
    ProtectionRepo-->>-ProtectionSvc: Success/Failure
    ProtectionSvc-->>-Processor: {success: true} / Exception
    deactivate ProtectionSvc
    Processor->>+QueueDB: UPDATE operation_queue SET status='completed'/'failed'
    QueueDB-->>-Processor: Success/Failure
    Processor->>+EventSys: addEvent('operation.completed', ...)
    EventSys-->>-Processor: Success/Failure
    deactivate Processor
    deactivate EventSys
```

**Flow:**
1. User clicks "Start Protection".
2. `dashboard.js` calls `startProtection()`, which iterates through selected items.
3. For each item, `queueManager.addToQueue('protect', ...)` is called.
4. `queue-manager.js` sends a POST request to the `/api/v1/queue` endpoint.
5. `QueueEndpoint::add()` receives the request and calls `QueueService::addOperation()`.
6. `QueueService` inserts a 'pending' protect operation into the `queue.db`.
7. The background `process_queue.php` script fetches the pending operation.
8. It retrieves the `Protection` service and calls its `protect()` method.
9. `Protection::protect()` calls `ProtectionOperations::createPar2Files()`.
10. `ProtectionOperations` builds and executes the `par2 create` command via `Par2CreateCommandBuilder` and `executePar2Command`/`executeMultiplePar2Commands`.
11. `Protection::protect()` calls `MetadataManager::storeMetadata()` and updates the database via `ProtectionRepository`.
12. `process_queue.php` updates the operation status in `queue.db`.
13. `process_queue.php` sends an `operation.completed` event via `EventSystem` for frontend updates.

## 2. Verify All (from Dashboard)

This diagram shows the sequence when the "Verify All" button is clicked on the dashboard.

```mermaid
sequenceDiagram
    participant User
    participant DashboardUI (dashboard.js)
    participant QueueMgr (queue-manager.js)
    participant API (QueueEndpoint.php / ProtectionEndpoint.php)
    participant QueueSvc (queue.php)
    participant QueueDB (queue.db)
    participant Processor (process_queue.php)
    participant VerificationSvc (Verification.php)
    participant VerificationOps (VerificationOperations.php)
    participant VerificationRepo (VerificationRepository.php)
    participant MetadataMgr (MetadataManager.php)
    participant PAR2CLI
    participant EventSys (EventSystem.php)

    User->>+DashboardUI: Click "Verify All"
    DashboardUI->>DashboardUI: startVerification('all')
    DashboardUI->>DashboardUI: showVerificationOptionsDialog('all')
    User->>+DashboardUI: Select options, Click "Continue"
    DashboardUI->>DashboardUI: executeVerification(options)
    DashboardUI->>+API: GET /api/v1/protection (Fetch all items)
    API-->>-DashboardUI: List of protected items
    loop for each item
        DashboardUI->>+QueueMgr: addToQueue('verify', {path, id, force:true, options})
        QueueMgr->>+API: POST /api/v1/queue (type='verify', params)
        API->>+QueueSvc: addOperation('verify', params)
        QueueSvc->>+QueueDB: INSERT INTO operation_queue (status='pending')
        QueueDB-->>-QueueSvc: operation_id
        QueueSvc-->>-API: {success: true, operation_id}
        API-->>-QueueMgr: {success: true, operation_id}
        QueueMgr->>DashboardUI: successCallback()
        deactivate QueueMgr
    end
    DashboardUI-->>-User: Show "Verification Started" / Update UI
    deactivate DashboardUI

    Note over Processor: Periodically checks queue...
    Processor->>+QueueDB: SELECT ... WHERE status='pending' LIMIT 1
    QueueDB-->>-Processor: 'verify' operation data (params)
    Processor->>Processor: Mark operation 'processing' in QueueDB
    Processor->>+VerificationSvc: verifyById(id, force, options) / verify(path, force, options)
    VerificationSvc->>+VerificationRepo: getProtectedItem(...)
    VerificationRepo-->>-VerificationSvc: Item details (par2Path, mode)
    VerificationSvc->>+VerificationOps: verifyPar2Files(...)
    VerificationOps->>PAR2CLI: Executes `par2 verify` command
    PAR2CLI-->>-VerificationOps: Result (status, details)
    VerificationOps-->>-VerificationSvc: {status, details}
    alt Metadata Verification Requested
        VerificationSvc->>+MetadataMgr: verifyMetadata(...)
        MetadataMgr-->>-VerificationSvc: {status, details}
        VerificationSvc->>VerificationSvc: Combine PAR2 & Metadata results
    end
    VerificationSvc->>+VerificationRepo: updateVerificationStatus(...)
    VerificationRepo-->>-VerificationSvc: Success/Failure
    VerificationSvc->>+VerificationRepo: clearVerificationCache(...)
    VerificationRepo-->>-VerificationSvc: Success/Failure
    VerificationSvc-->>-Processor: {success: true, status, details} / Exception
    deactivate VerificationSvc
    Processor->>+QueueDB: UPDATE operation_queue SET status='completed'/'failed'
    QueueDB-->>-Processor: Success/Failure
    Processor->>+EventSys: addEvent('operation.completed', ...)
    EventSys-->>-Processor: Success/Failure
    deactivate Processor
    deactivate EventSys
```

**Flow:**
1. User clicks "Verify All".
2. `dashboard.js` calls `startVerification('all')`, which calls `showVerificationOptionsDialog()`.
3. User selects options and clicks "Continue".
4. `executeVerification(options)` is called.
5. It first makes an AJAX GET request to `/api/v1/protection` to fetch all protected items.
6. For each item returned, it calls `queueManager.addToQueue('verify', ...)` with the item's details and selected options.
7. `queueManager` sends POST requests to `/api/v1/queue` for each item.
8. `QueueEndpoint` adds 'verify' operations to the queue via `QueueService`.
9. `process_queue.php` fetches each 'verify' operation.
10. It retrieves the `Verification` service and calls `verifyById()` or `verify()`.
11. `Verification::verify*()` calls `VerificationOperations::verifyPar2Files()`.
12. `VerificationOperations` uses `Par2VerifyCommandBuilder` and executes the `par2 verify` command.
13. If metadata verification was requested, `Verification::verify*()` calls `MetadataManager::verifyMetadata()`.
14. `Verification::verify*()` updates the status via `VerificationRepository`.
15. `process_queue.php` updates the queue item status and sends an `operation.completed` event.

## 3. Remove Selected (from List)

This diagram shows the sequence when items are selected in the list view and the "Remove Selected" button is clicked.

```mermaid
sequenceDiagram
    participant User
    participant ListUI (list.js)
    participant QueueMgr (queue-manager.js)
    participant API (QueueEndpoint.php)
    participant QueueSvc (queue.php)
    participant QueueDB (queue.db)
    participant Processor (process_queue.php)
    participant ProtectionSvc (Protection.php)
    participant ProtectionOps (ProtectionOperations.php)
    participant ProtectionRepo (ProtectionRepository.php)
    participant EventSys (EventSystem.php)

    User->>+ListUI: Select items, Click "Remove Selected"
    ListUI->>ListUI: removeSelectedProtections()
    ListUI->>+QueueMgr: addToQueue('remove', {path, id}) for each selected item
    QueueMgr->>+API: POST /api/v1/queue (type='remove', params)
    API->>+QueueSvc: addOperation('remove', params)
    QueueSvc->>+QueueDB: INSERT INTO operation_queue (status='pending')
    QueueDB-->>-QueueSvc: operation_id
    QueueSvc-->>-API: {success: true, operation_id}
    API-->>-QueueMgr: {success: true, operation_id}
    QueueMgr->>ListUI: successCallback()
    deactivate QueueMgr
    ListUI-->>-User: Update UI (show pending)
    deactivate ListUI

    Note over Processor: Periodically checks queue...
    Processor->>+QueueDB: SELECT ... WHERE status='pending' LIMIT 1
    QueueDB-->>-Processor: 'remove' operation data (params)
    Processor->>Processor: Mark operation 'processing' in QueueDB
    Processor->>+ProtectionSvc: removeById(id) / remove(path)
    ProtectionSvc->>+ProtectionRepo: findById(id) / findByPath(path)
    ProtectionRepo-->>-ProtectionSvc: Item details (par2Path, mode)
    ProtectionSvc->>+ProtectionOps: removeParityFiles(par2Path, isIndividual)
    ProtectionOps->>ProtectionOps: (Deletes files/dirs using unlink/rmdir/removeDirectoryRecursive)
    ProtectionOps-->>-ProtectionSvc: Success/Failure
    ProtectionSvc->>+ProtectionOps: cleanupOrphanedParityDirs(path)
    ProtectionOps->>ProtectionOps: (Glob finds/deletes dirs using removeDirectoryRecursive)
    ProtectionOps-->>-ProtectionSvc: Success/Failure
    ProtectionSvc->>+ProtectionRepo: removeItem(id)
    ProtectionRepo-->>-ProtectionSvc: Success/Failure
    ProtectionSvc-->>-Processor: {success: true} / Exception
    deactivate ProtectionSvc
    Processor->>+QueueDB: UPDATE operation_queue SET status='completed'/'failed'
    QueueDB-->>-Processor: Success/Failure
    Processor->>+EventSys: addEvent('operation.completed', ...)
    EventSys-->>-Processor: Success/Failure
    deactivate Processor
    deactivate EventSys
```

**Flow:**
1. User selects items and clicks "Remove Selected".
2. `list.js` calls `removeSelectedProtections()`.
3. For each selected item, `queueManager.addToQueue('remove', ...)` is called.
4. `queueManager` sends POST requests to `/api/v1/queue`.
5. `QueueEndpoint` adds 'remove' operations to the queue via `QueueService`.
6. `process_queue.php` fetches each 'remove' operation.
7. It retrieves the `Protection` service and calls `removeById()` or `remove()`.
8. `Protection::remove*()` retrieves item details via `ProtectionRepository`.
9. It calls `ProtectionOperations::removeParityFiles()` to delete PAR2 files/dirs.
10. It calls `ProtectionOperations::cleanupOrphanedParityDirs()` as a fallback.
11. It calls `ProtectionRepository::removeItem()` to delete the database entry.
12. `process_queue.php` updates the queue item status and sends an `operation.completed` event.