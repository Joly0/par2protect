<?php
/**
 * API Routes
 * 
 * This file defines all API routes for the Par2Protect plugin.
 */

// Protection endpoints
$router->get('/protection', 'ProtectionEndpoint@getAll');
$router->get('/protection/:path', 'ProtectionEndpoint@getStatus');
$router->post('/protection', 'ProtectionEndpoint@protect');
$router->delete('/protection/:path', 'ProtectionEndpoint@remove');
$router->get('/protection/:path/redundancy', 'ProtectionEndpoint@getRedundancyLevel');
$router->post('/protection/redundancy', 'ProtectionEndpoint@getRedundancyLevels');
$router->post('/protection/reprotect', 'ProtectionEndpoint@reprotect');
$router->post('/protection/files', 'ProtectionEndpoint@getFiles');

// Verification endpoints
$router->get('/verification/:path', 'VerificationEndpoint@getStatus');
$router->post('/verification', 'VerificationEndpoint@verify');
$router->post('/verification/repair', 'VerificationEndpoint@repair');

// Queue endpoints
$router->get('/queue', 'QueueEndpoint@getAll');
$router->get('/queue/active', 'QueueEndpoint@getActive');
$router->get('/queue/:id', 'QueueEndpoint@getStatus');
$router->post('/queue', 'QueueEndpoint@add');
$router->delete('/queue/:id', 'QueueEndpoint@cancel');
$router->post('/queue/cleanup', 'QueueEndpoint@cleanup');
$router->post('/queue/kill', 'QueueEndpoint@killStuck');

// Status endpoint
$router->get('/status', 'StatusEndpoint@getStatus');

// Settings endpoints
$router->get('/settings', 'SettingsEndpoint@getAll');
$router->put('/settings', 'SettingsEndpoint@update');
$router->post('/settings/reset', 'SettingsEndpoint@reset');

// Log endpoints
$router->get('/logs/activity', 'LogEndpoint@getActivity');
$router->get('/logs/entries', 'LogEndpoint@getEntries');
$router->post('/logs/clear', 'LogEndpoint@clear');
$router->get('/logs/download', 'LogEndpoint@download');
// SSE Endpoint
$router->get('/events', 'EventsEndpoint@getEvents');

// Debug endpoint (temporary for troubleshooting)
$router->get('/debug/services', 'DebugEndpoint@getServices');