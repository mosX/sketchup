<?php

use App\Mcp\Servers\WoodworkingServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/api/mcp/woodworking', WoodworkingServer::class)
    ->middleware(['auth:sanctum', 'ability:projects:read,projects:write', 'throttle:60,1,mcp:']);
