<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CvController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

// Public Authentication Routes (Rate Limited)
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Authenticated User Routes
Route::middleware(['auth:sanctum', 'active_user', 'throttle:api'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Profile, Password, Preferences & Privacy Deletion
    Route::put('/profile', [ProfileController::class, 'updateProfile']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences']);
    Route::delete('/profile/data', [ProfileController::class, 'deleteUserData']);

    // AI Status & Explore AI Catalog
    Route::get('/ai/status', [ChatController::class, 'status']);
    Route::get('/assistants/catalog', [AssistantController::class, 'catalog']);

    // Conversations & Smart AI Chat
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{uuid}', [ConversationController::class, 'show']);
    Route::patch('/conversations/{uuid}', [ConversationController::class, 'update']);
    Route::delete('/conversations/{uuid}', [ConversationController::class, 'destroy']);

    Route::middleware('throttle:chat')->group(function () {
        Route::post('/conversations/{uuid}/messages', [ChatController::class, 'sendMessage']);
        Route::put('/messages/{messageUuid}', [ChatController::class, 'editMessage']);
        Route::post('/messages/{messageUuid}/regenerate', [ChatController::class, 'regenerateMessage']);
    });
    Route::patch('/messages/{messageUuid}/meta', [ChatController::class, 'updateMessageMeta']);

    // AI Personal Memory
    Route::get('/memories', [MemoryController::class, 'index']);
    Route::post('/memories', [MemoryController::class, 'store']);
    Route::patch('/memories/{id}', [MemoryController::class, 'update']);
    Route::delete('/memories/{id}', [MemoryController::class, 'destroy']);
    Route::delete('/memories', [MemoryController::class, 'clearAll']);

    // Document Intelligence
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:uploads');
    Route::post('/documents/compare', [DocumentController::class, 'compare'])->middleware('throttle:chat');
    Route::get('/documents/{uuid}', [DocumentController::class, 'show']);
    Route::post('/documents/{uuid}/summarize', [DocumentController::class, 'summarize'])->middleware('throttle:chat');
    Route::delete('/documents/{uuid}', [DocumentController::class, 'destroy']);

    // AI CV Intelligence
    Route::get('/cvs', [CvController::class, 'index']);
    Route::post('/cvs', [CvController::class, 'store']);
    Route::post('/cvs/import', [CvController::class, 'importFromFile'])->middleware('throttle:uploads');
    Route::get('/cvs/{uuid}', [CvController::class, 'show']);
    Route::put('/cvs/{uuid}', [CvController::class, 'update']);
    Route::post('/cvs/{uuid}/ai-review', [CvController::class, 'aiReview'])->middleware('throttle:chat');
    Route::post('/cvs/{uuid}/tailor', [CvController::class, 'tailor'])->middleware('throttle:chat');
    Route::post('/cvs/{uuid}/cover-letter', [CvController::class, 'coverLetter'])->middleware('throttle:chat');
    Route::get('/cvs/{uuid}/export-pdf', [CvController::class, 'exportPdf']);
    Route::delete('/cvs/{uuid}', [CvController::class, 'destroy']);

    // Admin Dashboard (Strict Admin Authorization)
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/providers', [AdminController::class, 'listProviders']);
        Route::post('/providers', [AdminController::class, 'storeProvider']);
        Route::put('/providers/{id}', [AdminController::class, 'updateProvider']);
        Route::get('/users', [AdminController::class, 'listUsers']);
        Route::patch('/users/{id}', [AdminController::class, 'updateUser']);
    });
});
