<?php

use App\Http\Controllers\AdminScheduleController;
use App\Http\Controllers\AffiliateScheduleController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingAvailabilityController;
use App\Http\Controllers\BookingPaymentController;
use App\Http\Controllers\ClientInfoController;
use App\Http\Controllers\PaymentSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PsychologistController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\BookingConfirmationController;
use App\Http\Controllers\ClientAppointmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminClientController;
use App\Http\Controllers\AssessmentTypeController;
use App\Http\Controllers\HomeContentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\AboutController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/google', [AuthController::class, 'handleGoogleAuth']);

Route::post('/auth/facebook', [SocialAuthController::class, 'facebook']);
Route::post('/email/verify', [SocialAuthController::class, 'verify'])->middleware('throttle:6,1');
Route::post('/email/resend', [SocialAuthController::class, 'resend'])->middleware('throttle:3,1');

Route::get('/psychologists', [PsychologistController::class, 'index']);
Route::get('/psychologists/{id}', [PsychologistController::class, 'show']);
Route::get('/home-content', [HomeContentController::class, 'show']);
Route::get('/about/team', [AboutController::class, 'team']); 
// Public: services list (prices included, only enabled ones)
Route::get('/services', [ServiceController::class, 'index']);

// Public: assessment sub-types (for booking flow)
Route::get('/assessment-types', [AssessmentTypeController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/messages/contacts', [App\Http\Controllers\MessageController::class, 'getContacts']);

    // Booking: availability + temporary slot hold
    Route::get('/booking/availability', [BookingAvailabilityController::class, 'days']);
    Route::get('/booking/slots', [BookingAvailabilityController::class, 'slots']);
    Route::post('/booking/hold', [BookingAvailabilityController::class, 'hold']);
    Route::delete('/booking/hold/{id}', [BookingAvailabilityController::class, 'release']);
    Route::get('/booking/psychologists', [BookingAvailabilityController::class, 'psychologistsForSlot']);

    Route::get('/booking/client-info', [ClientInfoController::class, 'show']);
    Route::post('/booking/client-info', [ClientInfoController::class, 'store']);

    // Client: payment
    Route::get('/booking/payment-options', [BookingPaymentController::class, 'options']);
    Route::post('/booking/payments', [BookingPaymentController::class, 'submit'])->middleware('throttle:10,1');
    Route::get('/booking/payments/latest', [BookingPaymentController::class, 'latest']);

    Route::get('/booking/consent', [ConsentController::class, 'show']);
    Route::post('/booking/consent', [ConsentController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/booking/active', [BookingPaymentController::class, 'active']);
    Route::post('/booking/payments/{id}/finish', [BookingPaymentController::class, 'finish']);
    
    Route::post('/booking/payments/{id}/retry', [BookingPaymentController::class, 'retry']);
    Route::get('/booking/confirmation', [BookingConfirmationController::class, 'show']);

    Route::get('/my-appointments', [ClientAppointmentController::class, 'index']);
    Route::post('/my-appointments/{id}/cancel', [ClientAppointmentController::class, 'cancel']);
    Route::post('/my-appointments/{id}/reschedule', [ClientAppointmentController::class, 'reschedule'])->middleware('throttle:10,1');

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
    Route::post('/profile/avatar/remove', [ProfileController::class, 'removeAvatar']);
    Route::post('/profile/password', [ProfileController::class, 'password']);

    // Email change (6-digit code verification)
    Route::post('/profile/email/request', [ProfileController::class, 'requestEmailChange'])->middleware('throttle:5,1');
    Route::post('/profile/email/confirm', [ProfileController::class, 'confirmEmailChange'])->middleware('throttle:10,1');

    // Admin schedules
    Route::middleware('role:ADMIN')->prefix('admin/schedule')->group(function () {
        Route::get('/', [AdminScheduleController::class, 'index']);
        Route::post('/slots', [AdminScheduleController::class, 'store']);
        Route::delete('/slots/{id}', [AdminScheduleController::class, 'destroy']);
    });

    // Admin: payment settings, received payments, appointments
    Route::middleware('role:ADMIN')->prefix('admin')->group(function () {
        Route::get('/payment-settings', [PaymentSettingsController::class, 'show']);
        Route::put('/payment-settings', [PaymentSettingsController::class, 'update']);
        Route::post('/payment-methods', [PaymentSettingsController::class, 'storeMethod']);
        Route::put('/payment-methods/{id}', [PaymentSettingsController::class, 'updateMethod']);
        Route::delete('/payment-methods/{id}', [PaymentSettingsController::class, 'destroyMethod']);

        Route::get('/payments', [BookingPaymentController::class, 'index']);
        Route::post('/payments/{id}/verify', [BookingPaymentController::class, 'verify']);
        Route::post('/payments/{id}/reject', [BookingPaymentController::class, 'reject']);

        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::post('/appointments/{id}/approve', [AppointmentController::class, 'approve']);
        Route::post('/appointments/{id}/complete', [AppointmentController::class, 'complete']);
        Route::get('/appointments/{id}/consent', [AppointmentController::class, 'consent']);
        Route::get('/appointments/history', [AppointmentController::class, 'history']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/calendar', [DashboardController::class, 'calendar']);

        // Home content management
        Route::post('/home-content', [HomeContentController::class, 'update']);
        Route::put('/home-content', [HomeContentController::class, 'update']);
        Route::post('/home-content/image', [HomeContentController::class, 'uploadImage']);
        Route::get('/about-team', [AboutController::class, 'adminTeam']);
        // Services management (descriptions, hours, visibility)
        Route::get('/services', [ServiceController::class, 'adminIndex']);
        Route::post('/services', [ServiceController::class, 'store']);
        Route::put('/services/{slug}', [ServiceController::class, 'adminUpdate']);
        Route::post('/services/{slug}/image', [ServiceController::class, 'uploadImage']);
        Route::delete('/services/{slug}/image', [ServiceController::class, 'deleteImage']);
        Route::delete('/services/{slug}', [ServiceController::class, 'destroy']);

        // Assessment sub-types management
        Route::get('/assessment-types', [AssessmentTypeController::class, 'adminIndex']);
        Route::post('/assessment-types', [AssessmentTypeController::class, 'store']);
        Route::put('/assessment-types/{id}', [AssessmentTypeController::class, 'update']);
        Route::delete('/assessment-types/{id}', [AssessmentTypeController::class, 'destroy']);

        // Account management
        Route::get('/accounts', [AdminAccountController::class, 'index']);
        Route::post('/accounts', [AdminAccountController::class, 'store']);
        Route::put('/accounts/{id}', [AdminAccountController::class, 'update']);
        Route::post('/accounts/{id}/status', [AdminAccountController::class, 'updateStatus']);
        Route::delete('/accounts/{id}', [AdminAccountController::class, 'destroy']);

        // Client management
        Route::get('/clients', [AdminClientController::class, 'index']);
        Route::get('/clients/{id}', [AdminClientController::class, 'show']);
        Route::put('/clients/{id}', [AdminClientController::class, 'update']);
    });

    // Affiliate: payments + appointments (walang settings)
    Route::middleware('role:AFFILIATE')->prefix('affiliate')->group(function () {
        Route::get('/payments', [BookingPaymentController::class, 'index']);
        Route::post('/payments/{id}/verify', [BookingPaymentController::class, 'verify']);
        Route::post('/payments/{id}/reject', [BookingPaymentController::class, 'reject']);

        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::post('/appointments/{id}/approve', [AppointmentController::class, 'approve']);
        Route::post('/appointments/{id}/complete', [AppointmentController::class, 'complete']);
        Route::get('/appointments/{id}/consent', [AppointmentController::class, 'consent']);
        Route::get('/appointments/history', [AppointmentController::class, 'history']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/calendar', [DashboardController::class, 'calendar']);
    });

    Route::get('/messages', [App\Http\Controllers\MessageController::class, 'index']);
    Route::post('/messages', [App\Http\Controllers\MessageController::class, 'store']);

    // Affiliate schedules
    Route::middleware('role:AFFILIATE')->prefix('affiliate/schedule')->group(function () {
        Route::get('/', [AffiliateScheduleController::class, 'index']);
        Route::post('/slots', [AffiliateScheduleController::class, 'store']);
        Route::delete('/slots/{id}', [AffiliateScheduleController::class, 'destroy']);
    });
        // Agora Video Call Token
    Route::post('/agora/token', [App\Http\Controllers\AgoraController::class, 'generateToken']);
});
