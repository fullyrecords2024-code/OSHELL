<?php

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\ClinicalEncounterController;
use App\Http\Controllers\Api\V1\CrmController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EcommerceController;
use App\Http\Controllers\Api\V1\HrPayrollController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\InventoryProcurementController;
use App\Http\Controllers\Api\V1\MailboxController;
use App\Http\Controllers\Api\V1\MarketingIntegrationsController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OverviewController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\PosPinController;
use App\Http\Controllers\Api\V1\PosReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\ServiceCatalogueController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebsiteContentController;
use App\Http\Controllers\Api\V1\WebsiteGrowthController;
use App\Http\Controllers\Api\V1\WebsiteSeoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/public/storefront', [EcommerceController::class, 'storefront'])
        ->middleware('throttle:90,1,public-storefront-index')
        ->name('public.storefront.index');
    Route::post('/public/storefront/register', [EcommerceController::class, 'register'])
        ->middleware('throttle:6,1,public-storefront-register')
        ->name('public.storefront.register');
    Route::post('/public/storefront/register/verify', [EcommerceController::class, 'verifyRegistrationOtp'])
        ->middleware('throttle:10,1,public-storefront-register-verify')
        ->name('public.storefront.register.verify');
    Route::post('/public/storefront/register/resend', [EcommerceController::class, 'resendRegistrationOtp'])
        ->middleware('throttle:6,1,public-storefront-register-resend')
        ->name('public.storefront.register.resend');
    Route::get('/public/services', [ServiceCatalogueController::class, 'publicIndex'])
        ->middleware('throttle:60,1,public-services-index')
        ->name('public.services.index');
    Route::get('/public/services/{slug}', [ServiceCatalogueController::class, 'publicService'])
        ->middleware('throttle:90,1,public-services-show')
        ->name('public.services.show');
    Route::get('/public/products/{slug}', [EcommerceController::class, 'publicProduct'])
        ->middleware('throttle:90,1,public-products-show')
        ->name('public.products.show');
    Route::get('/public/site-content', [WebsiteContentController::class, 'publicPage'])
        ->middleware('throttle:60,1,public-site-content-show')
        ->name('public.site-content.show');
    Route::get('/public/seo/redirect', [WebsiteSeoController::class, 'resolveRedirect'])
        ->middleware('throttle:60,1,public-seo-redirect')
        ->name('public.seo.redirect');
    Route::post('/public/analytics/events', [WebsiteSeoController::class, 'recordPageView'])
        ->middleware('throttle:60,1,public-analytics-events-store')
        ->name('public.analytics.events.store');
    Route::get('/public/journal', [WebsiteGrowthController::class, 'publicJournal'])
        ->middleware('throttle:60,1,public-journal-index')
        ->name('public.journal.index');
    Route::get('/public/reviews', [WebsiteGrowthController::class, 'publicReviews'])
        ->middleware('throttle:60,1,public-reviews-index')
        ->name('public.reviews.index');
    Route::get('/public/promotions', [WebsiteGrowthController::class, 'publicPromotions'])
        ->middleware('throttle:60,1,public-promotions-index')
        ->name('public.promotions.index');
    Route::get('/public/product-sections', [WebsiteGrowthController::class, 'publicSections'])
        ->middleware('throttle:60,1,public-product-sections-index')
        ->name('public.product-sections.index');
    Route::get('/public/social-links', [WebsiteGrowthController::class, 'publicSocialLinks'])
        ->middleware('throttle:60,1,public-social-links-index')
        ->name('public.social-links.index');
    Route::post('/public/newsletter/subscribe', [WebsiteGrowthController::class, 'subscribe'])
        ->middleware('throttle:6,1,public-newsletter-subscribe')
        ->name('public.newsletter.subscribe');
    Route::get('/public/newsletter/confirm/{token}', [WebsiteGrowthController::class, 'showNewsletterConfirmation'])
        ->middleware('throttle:12,1,public-newsletter-confirm-show')
        ->name('public.newsletter.confirm.show');
    Route::post('/public/newsletter/confirm/{token}', [WebsiteGrowthController::class, 'confirmNewsletter'])
        ->middleware('throttle:12,1,public-newsletter-confirm')
        ->name('public.newsletter.confirm');
    Route::post('/public/privacy-consent', [WebsiteGrowthController::class, 'savePrivacyConsent'])
        ->middleware('throttle:12,1,public-privacy-consent-store')
        ->name('public.privacy-consent.store');
    Route::post('/public/newsletter/unsubscribe/{token}', [WebsiteGrowthController::class, 'unsubscribe'])
        ->middleware('throttle:12,1,public-newsletter-unsubscribe')
        ->name('public.newsletter.unsubscribe');
    Route::post('/public/enquiries', [CrmController::class, 'createPublicEnquiry'])
        ->middleware('throttle:6,1,public-enquiries-store')
        ->name('public.enquiries.store');
    Route::get('/public/availability', [AppointmentController::class, 'publicAvailability'])
        ->middleware('throttle:60,1,public-availability-index')
        ->name('public.availability.index');
    Route::get('/payments/pesapal/callback', [PaymentController::class, 'pesapalCallback'])
        ->middleware('throttle:60,1,payments-pesapal-callback')
        ->name('payments.pesapal.callback');
    Route::get('/payments/pesapal/ipn', [PaymentController::class, 'pesapalIpn'])
        ->middleware('throttle:60,1,payments-pesapal-ipn')
        ->name('payments.pesapal.ipn');
    Route::post('/payments/mpesa/callback', [PaymentController::class, 'mpesaCallback'])
        ->middleware('throttle:60,1,payments-mpesa-callback')
        ->name('payments.mpesa.callback');
    Route::get('/marketing/integrations/{platform}/callback', [MarketingIntegrationsController::class, 'oauthCallback'])
        ->middleware('throttle:30,1,marketing-integrations-oauth-callback')
        ->name('marketing.integrations.oauth.callback');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');
        Route::post('/password/forgot', [AuthController::class, 'sendResetLink'])
            ->middleware('throttle:6,1,password-forgot')
            ->name('password.forgot');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:6,1,password-reset')
            ->name('password.reset');
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::get('/sessions', [AuthController::class, 'sessions'])->name('sessions.index');
            Route::delete('/sessions/{tokenId}', [AuthController::class, 'revokeSession'])->name('sessions.destroy');
            Route::put('/password', [AuthController::class, 'updatePassword'])->name('password.update');
            Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationNotification'])
                ->middleware('throttle:6,1,verification-send')
                ->name('verification.send');
        });
    });

    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1,verification-verify'])
        ->name('verification.verify');

    Route::middleware(['auth:sanctum', 'verified', 'password.changed'])->group(function (): void {
        Route::get('/notifications', [InboxController::class, 'index'])
            ->name('notifications.index');
        Route::get('/notifications/unread-count', [InboxController::class, 'unreadCount'])
            ->name('notifications.unread-count');
        Route::post('/notifications/read-all', [InboxController::class, 'markAllRead'])
            ->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [InboxController::class, 'markRead'])
            ->whereUuid('notification')
            ->name('notifications.read');

        Route::get('/mailbox', [MailboxController::class, 'index'])
            ->name('mailbox.index');
        Route::post('/mailbox/reply/{notification}', [MailboxController::class, 'reply'])
            ->whereUuid('notification')
            ->middleware('throttle:10,1,mailbox-reply')
            ->name('mailbox.reply');
        Route::post('/mailbox/messages', [MailboxController::class, 'compose'])
            ->middleware('throttle:10,1,mailbox-compose')
            ->name('mailbox.messages.store');

        Route::get('/documents/orders/{order}/invoice', [DocumentController::class, 'orderInvoice'])
            ->name('documents.orders.invoice');
        Route::get('/documents/orders/{order}/receipt', [DocumentController::class, 'orderReceipt'])
            ->name('documents.orders.receipt');
        Route::get('/documents/pos-sales/{sale}/receipt', [DocumentController::class, 'posReceipt'])
            ->middleware('can:pos.receipt.view')
            ->name('documents.pos.receipt');
        Route::get('/documents/appointments/{appointment}', [DocumentController::class, 'appointmentConfirmation'])
            ->name('documents.appointments.show');

        Route::get('/portal/payment-options', [PaymentController::class, 'options'])
            ->name('portal.payment-options');
        Route::post('/portal/orders/{order}/payments', [PaymentController::class, 'initiateOrderPayment'])
            ->middleware(['can:ecommerce.orders.view_own', 'throttle:12,1,portal-orders-payments-store'])
            ->name('portal.orders.payments.store');
        Route::get('/portal/orders/{order}/payment-status', [PaymentController::class, 'orderPaymentStatus'])
            ->middleware('can:ecommerce.orders.view_own')
            ->name('portal.orders.payment-status');
        Route::post('/portal/newsletter/unsubscribe', [WebsiteGrowthController::class, 'unsubscribeCurrentUser'])
            ->name('portal.newsletter.unsubscribe');
        Route::get('/portal/rewards', [WebsiteGrowthController::class, 'loyaltySummary'])
            ->name('portal.rewards.show');
        Route::get('/portal/review-eligible', [WebsiteGrowthController::class, 'eligibleReviewSources'])
            ->name('portal.reviews.eligible');
        Route::post('/portal/reviews', [WebsiteGrowthController::class, 'submitReview'])
            ->middleware('throttle:10,1,portal-reviews-store')
            ->name('portal.reviews.store');
        Route::get('/portal/marketing-preferences', [CrmController::class, 'ownPreferences'])
            ->name('portal.marketing-preferences.index');
        Route::put('/portal/marketing-preferences', [CrmController::class, 'updateOwnPreference'])
            ->name('portal.marketing-preferences.update');

        Route::get('/website/loyalty', [WebsiteGrowthController::class, 'staffLoyalty'])
            ->middleware('can:loyalty.manage')
            ->name('website.loyalty.index');
        Route::put('/website/loyalty/program', [WebsiteGrowthController::class, 'updateLoyaltyProgram'])
            ->middleware('can:loyalty.manage')
            ->name('website.loyalty.program.update');
        Route::post('/website/loyalty/adjustments', [WebsiteGrowthController::class, 'adjustLoyalty'])
            ->middleware('can:loyalty.adjust')
            ->name('website.loyalty.adjustments.store');
        Route::get('/website/reviews', [WebsiteGrowthController::class, 'staffReviews'])
            ->middleware('can:website.reviews.manage')
            ->name('website.reviews.index');
        Route::patch('/website/reviews/{review}', [WebsiteGrowthController::class, 'moderateReview'])
            ->middleware('can:website.reviews.manage')
            ->name('website.reviews.moderate');
        Route::get('/website/product-sections', [WebsiteGrowthController::class, 'staffSections'])
            ->middleware('can:website.merchandising.manage')
            ->name('website.product-sections.index');
        Route::get('/website/products', [WebsiteGrowthController::class, 'staffProducts'])
            ->middleware('can:website.merchandising.manage')
            ->name('website.products.index');
        Route::put('/website/product-sections', [WebsiteGrowthController::class, 'saveSection'])
            ->middleware('can:website.merchandising.manage')
            ->name('website.product-sections.save');
        Route::get('/website/promotions', [WebsiteGrowthController::class, 'staffPromotions'])
            ->middleware('can:website.marketing.manage')
            ->name('website.promotions.index');
        Route::post('/website/promotions', [WebsiteGrowthController::class, 'savePromotion'])
            ->middleware('can:website.marketing.manage')
            ->name('website.promotions.store');
        Route::put('/website/promotions/{promotion}', [WebsiteGrowthController::class, 'updatePromotion'])
            ->middleware('can:website.marketing.manage')
            ->name('website.promotions.update');
        Route::get('/website/social-links', [WebsiteGrowthController::class, 'staffSocialLinks'])
            ->middleware('can:website.social.manage')
            ->name('website.social-links.index');
        Route::put('/website/social-links', [WebsiteGrowthController::class, 'saveSocialLinks'])
            ->middleware('can:website.social.manage')
            ->name('website.social-links.update');

        Route::get('/marketing/integrations', [MarketingIntegrationsController::class, 'integrations'])
            ->middleware('can:marketing.integrations.view')
            ->name('marketing.integrations.index');
        Route::post('/marketing/integrations/{platform}/authorize', [MarketingIntegrationsController::class, 'authorizeProvider'])
            ->middleware('can:marketing.integrations.manage')
            ->name('marketing.integrations.authorize');
        Route::delete('/marketing/integrations/{connection}', [MarketingIntegrationsController::class, 'disconnect'])
            ->middleware('can:marketing.integrations.manage')
            ->name('marketing.integrations.disconnect');
        Route::get('/marketing/external-campaigns', [MarketingIntegrationsController::class, 'externalCampaigns'])
            ->middleware('can:marketing.campaigns.view')
            ->name('marketing.external-campaigns.index');
        Route::post('/marketing/external-campaigns', [MarketingIntegrationsController::class, 'importExternalCampaign'])
            ->middleware('can:marketing.campaigns.manage')
            ->name('marketing.external-campaigns.store');
        Route::put('/marketing/external-campaigns/{campaign}/metrics', [MarketingIntegrationsController::class, 'updateExternalCampaignMetrics'])
            ->middleware('can:marketing.campaigns.manage')
            ->name('marketing.external-campaigns.metrics.update');
        Route::get('/marketing/social-posts', [MarketingIntegrationsController::class, 'socialPosts'])
            ->middleware('can:marketing.social.view')
            ->name('marketing.social-posts.index');
        Route::post('/marketing/social-posts', [MarketingIntegrationsController::class, 'createSocialPost'])
            ->middleware('can:marketing.social.manage')
            ->name('marketing.social-posts.store');
        Route::post('/marketing/social-posts/{post}/submit', [MarketingIntegrationsController::class, 'submitSocialPost'])
            ->middleware('can:marketing.social.manage')
            ->name('marketing.social-posts.submit');
        Route::post('/marketing/social-posts/{post}/review', [MarketingIntegrationsController::class, 'reviewSocialPost'])
            ->middleware('can:marketing.social.approve')
            ->name('marketing.social-posts.review');
        Route::post('/marketing/social-posts/{post}/record-publication', [MarketingIntegrationsController::class, 'recordExternalPublication'])
            ->middleware('can:marketing.social.manage')
            ->name('marketing.social-posts.publication.store');
        Route::get('/marketing/seo', [MarketingIntegrationsController::class, 'seoDashboard'])
            ->middleware('can:marketing.seo.view')
            ->name('marketing.seo.index');
        Route::post('/marketing/seo/keywords', [MarketingIntegrationsController::class, 'saveSeoKeyword'])
            ->middleware('can:marketing.seo.manage')
            ->name('marketing.seo.keywords.store');
        Route::delete('/marketing/seo/keywords/{keyword}', [MarketingIntegrationsController::class, 'deleteSeoKeyword'])
            ->middleware('can:marketing.seo.manage')
            ->name('marketing.seo.keywords.destroy');
        Route::put('/marketing/seo/branches/{branch}', [MarketingIntegrationsController::class, 'saveBranchSeo'])
            ->middleware('can:marketing.seo.manage')
            ->name('marketing.seo.branches.update');

        Route::get('/crm/overview', [CrmController::class, 'overview'])
            ->middleware('can:crm.view')
            ->name('crm.overview');
        Route::post('/crm/leads', [CrmController::class, 'createLead'])
            ->middleware('can:crm.leads.manage')
            ->name('crm.leads.store');
        Route::patch('/crm/leads/{lead}', [CrmController::class, 'updateLead'])
            ->middleware('can:crm.leads.manage')
            ->name('crm.leads.update');
        Route::post('/crm/leads/{lead}/conversions', [CrmController::class, 'linkConversion'])
            ->middleware('can:crm.leads.manage')
            ->name('crm.leads.conversions.store');
        Route::post('/crm/segments', [CrmController::class, 'saveSegment'])
            ->middleware('can:crm.segments.manage')
            ->name('crm.segments.store');
        Route::get('/crm/segments/{segment}/members', [CrmController::class, 'segmentMembers'])
            ->middleware('can:crm.segments.manage')
            ->name('crm.segments.members');
        Route::post('/crm/teams', [CrmController::class, 'createTeam'])
            ->middleware('can:crm.teams.manage')
            ->name('crm.teams.store');
        Route::put('/crm/teams/{team}/members', [CrmController::class, 'setTeamMembers'])
            ->middleware('can:crm.teams.manage')
            ->name('crm.teams.members.update');
        Route::post('/crm/tasks', [CrmController::class, 'createTask'])
            ->middleware('can:crm.tasks.manage')
            ->name('crm.tasks.store');
        Route::patch('/crm/tasks/{task}', [CrmController::class, 'updateTask'])
            ->middleware('can:crm.tasks.manage')
            ->name('crm.tasks.update');
        Route::post('/crm/campaigns', [CrmController::class, 'createCampaign'])
            ->middleware('can:crm.campaigns.manage')
            ->name('crm.campaigns.store');
        Route::post('/crm/campaigns/{campaign}/submit', [CrmController::class, 'submitCampaign'])
            ->middleware('can:crm.campaigns.manage')
            ->name('crm.campaigns.submit');
        Route::post('/crm/campaigns/{campaign}/approve', [CrmController::class, 'approveCampaign'])
            ->middleware('can:crm.campaigns.approve')
            ->name('crm.campaigns.approve');
        Route::patch('/crm/campaigns/{campaign}/status', [CrmController::class, 'updateCampaignStatus'])
            ->middleware('can:crm.campaigns.manage')
            ->name('crm.campaigns.status');
        Route::post('/crm/expenses', [CrmController::class, 'createExpense'])
            ->middleware('can:crm.budgets.manage')
            ->name('crm.expenses.store');
        Route::post('/crm/expenses/{expense}/review', [CrmController::class, 'reviewExpense'])
            ->middleware('can:crm.budgets.approve')
            ->name('crm.expenses.review');
        Route::put('/crm/customers/{customer}/preferences', [CrmController::class, 'updatePreference'])
            ->middleware('can:crm.consent.manage')
            ->name('crm.customers.preferences.update');

        Route::get('/website/pages', [WebsiteContentController::class, 'index'])
            ->middleware('can:website.content.view')
            ->name('website.pages.index');
        Route::post('/website/pages', [WebsiteContentController::class, 'store'])
            ->middleware('can:website.content.manage')
            ->name('website.pages.store');
        Route::put('/website/pages/{page}', [WebsiteContentController::class, 'update'])
            ->middleware('can:website.content.manage')
            ->name('website.pages.update');
        Route::patch('/website/pages/{page}/publication', [WebsiteContentController::class, 'setPublication'])
            ->middleware('can:website.content.publish')
            ->name('website.pages.publication.update');
        Route::post('/website/pages/{page}/media', [WebsiteContentController::class, 'uploadMedia'])
            ->middleware('can:website.content.manage')
            ->name('website.pages.media.store');
        Route::delete('/website/pages/{page}/media', [WebsiteContentController::class, 'deleteMedia'])
            ->middleware('can:website.content.manage')
            ->name('website.pages.media.destroy');
        Route::get('/website/redirects', [WebsiteContentController::class, 'redirects'])
            ->middleware('can:website.content.view')
            ->name('website.redirects.index');
        Route::post('/website/redirects', [WebsiteContentController::class, 'createRedirect'])
            ->middleware('can:website.content.manage')
            ->name('website.redirects.store');
        Route::delete('/website/redirects/{redirect}', [WebsiteContentController::class, 'deleteRedirect'])
            ->middleware('can:website.content.manage')
            ->name('website.redirects.destroy');

        Route::get('/portal/orders', [EcommerceController::class, 'orders'])
            ->middleware('can:ecommerce.orders.view_own')
            ->name('portal.orders.index');
        Route::post('/portal/orders', [EcommerceController::class, 'createOrder'])
            ->middleware(['can:ecommerce.orders.create', 'throttle:30,1,portal-orders-store'])
            ->name('portal.orders.store');
        Route::post('/portal/orders/{order}/cancel', [EcommerceController::class, 'cancelOrder'])
            ->middleware('can:ecommerce.orders.cancel_own')
            ->name('portal.orders.cancel');
        Route::post('/portal/orders/{order}/return-request', [EcommerceController::class, 'requestReturn'])
            ->middleware('can:ecommerce.returns.request')
            ->name('portal.orders.return-request');
        Route::get('/ecommerce/orders', [EcommerceController::class, 'staffOrders'])
            ->middleware('can:ecommerce.orders.view')
            ->name('ecommerce.orders.index');
        Route::patch('/ecommerce/orders/{order}/fulfillment', [EcommerceController::class, 'updateFulfillment'])
            ->middleware('can:ecommerce.orders.manage')
            ->name('ecommerce.orders.fulfillment');
        Route::post('/ecommerce/orders/{order}/return-decision', [EcommerceController::class, 'decideReturn'])
            ->middleware('can:ecommerce.returns.manage')
            ->name('ecommerce.orders.return-decision');

        Route::get('/overview', [OverviewController::class, 'index'])
            ->name('overview.index');
        Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard'])
            ->middleware('can:analytics.view')
            ->name('analytics.dashboard');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])
            ->middleware(['can:analytics.view', 'can:analytics.export'])
            ->name('analytics.export');

        Route::get('/organizations', [OrganizationController::class, 'index'])
            ->middleware('can:organization.view')
            ->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])
            ->middleware('can:organization.manage')
            ->name('organizations.store');
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
            ->middleware('can:organization.view')
            ->name('organizations.show');
        Route::match(['put', 'patch'], '/organizations/{organization}', [OrganizationController::class, 'update'])
            ->middleware('can:organization.manage')
            ->name('organizations.update');
        Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy'])
            ->middleware('can:organization.manage')
            ->name('organizations.destroy');

        Route::get('/branches', [BranchController::class, 'index'])
            ->middleware('can:branch.view')
            ->name('branches.index');
        Route::post('/branches', [BranchController::class, 'store'])
            ->middleware('can:branch.manage')
            ->name('branches.store');
        Route::get('/branches/{branch}', [BranchController::class, 'show'])
            ->middleware('can:branch.view')
            ->name('branches.show');
        Route::match(['put', 'patch'], '/branches/{branch}', [BranchController::class, 'update'])
            ->middleware('can:branch.manage')
            ->name('branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])
            ->middleware('can:branch.manage')
            ->name('branches.destroy');

        Route::get('/departments', [DepartmentController::class, 'index'])
            ->middleware('can:department.view')
            ->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])
            ->middleware('can:department.manage')
            ->name('departments.store');
        Route::get('/departments/{department}', [DepartmentController::class, 'show'])
            ->middleware('can:department.view')
            ->name('departments.show');
        Route::match(['put', 'patch'], '/departments/{department}', [DepartmentController::class, 'update'])
            ->middleware('can:department.manage')
            ->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('can:department.manage')
            ->name('departments.destroy');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('can:users.view')
            ->name('users.index');
        Route::post('/users', [UserController::class, 'store'])
            ->middleware(['can:users.manage', 'can:roles.assign'])
            ->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])
            ->middleware('can:users.view')
            ->name('users.show');
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])
            ->middleware(['can:users.manage', 'can:roles.assign'])
            ->name('users.update');

        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('can:roles.assign')
            ->name('roles.index');
        Route::get('/audit-events', [AuditEventController::class, 'index'])
            ->middleware('can:audit.view')
            ->name('audit-events.index');

        Route::get('/settings/email', [SettingsController::class, 'email'])
            ->middleware('can:settings.manage')
            ->name('settings.email.show');
        Route::put('/settings/email', [SettingsController::class, 'updateEmail'])
            ->middleware('can:settings.manage')
            ->name('settings.email.update');
        Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])
            ->middleware('can:settings.manage')
            ->name('settings.email.test');
        Route::get('/settings/notifications', [SettingsController::class, 'notifications'])
            ->middleware('can:settings.manage')
            ->name('settings.notifications.show');
        Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])
            ->middleware('can:settings.manage')
            ->name('settings.notifications.update');

        Route::get('/services', [ServiceCatalogueController::class, 'index'])
            ->middleware('can:services.view')
            ->name('services.index');
        Route::post('/service-categories', [ServiceCatalogueController::class, 'storeCategory'])
            ->middleware('can:services.manage')
            ->name('service-categories.store');
        Route::get('/service-categories', [ServiceCatalogueController::class, 'categories'])
            ->middleware('can:services.manage')
            ->name('service-categories.index');
        Route::post('/services', [ServiceCatalogueController::class, 'store'])
            ->middleware('can:services.manage')
            ->name('services.store');
        Route::match(['put', 'patch'], '/services/{service}', [ServiceCatalogueController::class, 'update'])
            ->middleware('can:services.manage')
            ->name('services.update');
        Route::put('/services/{service}/branches', [ServiceCatalogueController::class, 'configureBranch'])
            ->middleware('can:services.manage')
            ->name('services.branches.update');
        Route::post('/services/{service}/images', [ServiceCatalogueController::class, 'storeImages'])
            ->middleware('can:services.manage')
            ->name('services.images.store');
        Route::delete('/services/{service}/images/{image}', [ServiceCatalogueController::class, 'deleteImage'])
            ->middleware('can:services.manage')
            ->name('services.images.destroy');
        Route::patch('/services/{service}/images/{image}/primary', [ServiceCatalogueController::class, 'setPrimaryImage'])
            ->middleware('can:services.manage')
            ->name('services.images.primary');
        Route::get('/branch-business-hours', [ServiceCatalogueController::class, 'businessHours'])
            ->middleware('can:schedules.manage')
            ->name('branch-business-hours.index');
        Route::put('/branch-business-hours', [ServiceCatalogueController::class, 'setBusinessHours'])
            ->middleware('can:schedules.manage')
            ->name('branch-business-hours.update');
        Route::get('/booking-resources', [ServiceCatalogueController::class, 'resources'])
            ->middleware('can:schedules.manage')
            ->name('booking-resources.index');
        Route::post('/booking-resources', [ServiceCatalogueController::class, 'storeResource'])
            ->middleware('can:schedules.manage')
            ->name('booking-resources.store');
        Route::put('/booking-resources/{resource}/schedule', [ServiceCatalogueController::class, 'setResourceSchedule'])
            ->middleware('can:schedules.manage')
            ->name('booking-resources.schedule.update');

        Route::get('/availability', [AppointmentController::class, 'availability'])
            ->middleware('can:services.view')
            ->name('availability.index');
        Route::get('/appointments', [AppointmentController::class, 'index'])
            ->middleware('can:appointments.view')
            ->name('appointments.index');
        Route::post('/appointments', [AppointmentController::class, 'storeStaff'])
            ->middleware('can:appointments.create')
            ->name('appointments.store');
        Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'transition'])
            ->middleware('can:appointments.manage')
            ->name('appointments.status.update');
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
            ->middleware('can:appointments.manage')
            ->name('appointments.reschedule');
        Route::post('/appointments/{appointment}/deposit', [AppointmentController::class, 'recordDeposit'])
            ->middleware('can:appointments.deposit.manage')
            ->name('appointments.deposit.store');
        Route::get('/queue', [AppointmentController::class, 'queue'])
            ->middleware('can:queue.view')
            ->name('queue.index');
        Route::post('/queue/call-next', [AppointmentController::class, 'callNext'])
            ->middleware('can:queue.manage')
            ->name('queue.call-next');
        Route::get('/waitlist', [AppointmentController::class, 'waitlist'])
            ->middleware('can:waitlist.view')
            ->name('waitlist.index');
        Route::post('/waitlist', [AppointmentController::class, 'addToWaitlist'])
            ->middleware('can:waitlist.manage')
            ->name('waitlist.store');
        Route::patch('/waitlist/{entry}', [AppointmentController::class, 'updateWaitlist'])
            ->middleware('can:waitlist.manage')
            ->name('waitlist.update');

        Route::get('/portal/appointments', [AppointmentController::class, 'index'])
            ->name('portal.appointments.index');
        Route::post('/portal/appointments', [AppointmentController::class, 'storePortal'])
            ->middleware('can:appointments.book.self')
            ->name('portal.appointments.store');
        Route::patch('/portal/appointments/{appointment}/cancel', [AppointmentController::class, 'transition'])
            ->middleware('can:appointments.cancel.own')
            ->name('portal.appointments.cancel');
        Route::post('/portal/waitlist', [AppointmentController::class, 'addToWaitlist'])
            ->middleware('can:appointments.book.self')
            ->name('portal.waitlist.store');

        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('can:customers.view')
            ->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('can:customers.create')
            ->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('can:customers.view')
            ->name('customers.show');
        Route::get('/customers/{customer}/activity', [CustomerController::class, 'activity'])
            ->middleware('can:customers.view')
            ->name('customers.activity');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])
            ->middleware('can:customers.update')
            ->name('customers.update');
        Route::post('/customers/{customer}/branch-history', [CustomerController::class, 'recordBranchVisit'])
            ->middleware('can:customers.history.record')
            ->name('customers.branch-history.store');
        Route::post('/customers/{customer}/consents', [CustomerController::class, 'storeConsent'])
            ->middleware('can:consents.manage')
            ->name('customers.consents.store');
        Route::get('/customers/{customer}/consents/{consent}/document', [CustomerController::class, 'consentDocument'])
            ->middleware('can:consents.view')
            ->name('customers.consents.document');
        Route::get('/customers/{customer}/patient-profile', [CustomerController::class, 'patientProfile'])
            ->middleware('can:patients.view')
            ->name('customers.patient-profile.show');
        Route::put('/customers/{customer}/patient-profile', [CustomerController::class, 'updatePatientProfile'])
            ->middleware('can:patients.manage')
            ->name('customers.patient-profile.update');
        Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])
            ->middleware('can:customers.merge')
            ->name('customers.merge');
        Route::post('/customers/{customer}/portal-activation', [CustomerController::class, 'activatePortal'])
            ->middleware('can:customer.portal.activate')
            ->name('customers.portal-activation');

        Route::get('/portal/profile', [CustomerController::class, 'portalProfile'])
            ->name('portal.profile.show');
        Route::patch('/portal/profile', [CustomerController::class, 'updatePortalProfile'])
            ->name('portal.profile.update');

        Route::get('/clinical/assessment-templates', [ClinicalEncounterController::class, 'templates'])
            ->middleware('can:clinical.view')
            ->name('clinical.templates.index');
        Route::get('/clinical/branches', [ClinicalEncounterController::class, 'branches'])
            ->middleware('can:clinical.view')
            ->name('clinical.branches.index');
        Route::get('/clinical/patients', [ClinicalEncounterController::class, 'patients'])
            ->middleware('can:clinical.view')
            ->name('clinical.patients.index');
        Route::post('/clinical/assessment-templates', [ClinicalEncounterController::class, 'storeTemplate'])
            ->middleware('can:clinical.templates.manage')
            ->name('clinical.templates.store');
        Route::get('/clinical/encounters', [ClinicalEncounterController::class, 'index'])
            ->middleware('can:clinical.view')
            ->name('clinical.encounters.index');
        Route::post('/clinical/encounters', [ClinicalEncounterController::class, 'store'])
            ->middleware('can:clinical.manage')
            ->name('clinical.encounters.store');
        Route::get('/clinical/encounters/{encounter}', [ClinicalEncounterController::class, 'show'])
            ->middleware('can:clinical.view')
            ->name('clinical.encounters.show');
        Route::put('/clinical/encounters/{encounter}', [ClinicalEncounterController::class, 'update'])
            ->middleware(['can:clinical.manage', 'can:clinical.amend'])
            ->name('clinical.encounters.update');
        Route::post('/clinical/encounters/{encounter}/complete', [ClinicalEncounterController::class, 'complete'])
            ->middleware('can:clinical.manage')
            ->name('clinical.encounters.complete');
        Route::get('/clinical/workflow-report', [ClinicalEncounterController::class, 'workflowReport'])
            ->middleware('can:clinical.reports.view')
            ->name('clinical.workflow-report');

        Route::get('/hr/employees', [HrPayrollController::class, 'employees'])
            ->middleware('can:hr.view')
            ->name('hr.employees.index');
        Route::post('/hr/employees', [HrPayrollController::class, 'storeEmployee'])
            ->middleware('can:hr.manage')
            ->name('hr.employees.store');
        Route::put('/hr/employees/{employee}/compensation', [HrPayrollController::class, 'setCompensation'])
            ->middleware(['can:payroll.config.manage', 'can:payroll.salary.view'])
            ->name('hr.employees.compensation.update');
        Route::get('/hr/shifts', [HrPayrollController::class, 'shifts'])
            ->middleware('can:hr.view')
            ->name('hr.shifts.index');
        Route::post('/hr/shifts', [HrPayrollController::class, 'storeShift'])
            ->middleware('can:hr.manage')
            ->name('hr.shifts.store');
        Route::get('/hr/attendance', [HrPayrollController::class, 'attendance'])
            ->middleware('can:attendance.view')
            ->name('hr.attendance.index');
        Route::post('/hr/attendance/clock-in', [HrPayrollController::class, 'clockIn'])
            ->middleware('can:attendance.manage')
            ->name('hr.attendance.clock-in');
        Route::post('/hr/attendance/{attendance}/clock-out', [HrPayrollController::class, 'clockOut'])
            ->middleware('can:attendance.manage')
            ->name('hr.attendance.clock-out');
        Route::get('/hr/leave-requests', [HrPayrollController::class, 'leaveRequests'])
            ->middleware('can:hr.view')
            ->name('hr.leave.index');
        Route::post('/hr/leave-requests', [HrPayrollController::class, 'storeLeave'])
            ->middleware('can:leave.manage')
            ->name('hr.leave.store');
        Route::patch('/hr/leave-requests/{leave}/decision', [HrPayrollController::class, 'decideLeave'])
            ->middleware('can:leave.manage')
            ->name('hr.leave.decision');

        Route::get('/payroll/components', [HrPayrollController::class, 'components'])
            ->middleware(['can:payroll.view', 'can:payroll.salary.view'])
            ->name('payroll.components.index');
        Route::post('/payroll/components', [HrPayrollController::class, 'storeComponent'])
            ->middleware(['can:payroll.config.manage', 'can:payroll.salary.view'])
            ->name('payroll.components.store');
        Route::get('/payroll/commissions', [HrPayrollController::class, 'commissions'])
            ->middleware(['can:payroll.view', 'can:payroll.salary.view'])
            ->name('payroll.commissions.index');
        Route::post('/payroll/commissions', [HrPayrollController::class, 'storeCommission'])
            ->middleware('can:payroll.commission.manage')
            ->name('payroll.commissions.store');
        Route::post('/payroll/commissions/{commission}/approve', [HrPayrollController::class, 'approveCommission'])
            ->middleware('can:payroll.commission.manage')
            ->name('payroll.commissions.approve');
        Route::get('/payroll/runs', [HrPayrollController::class, 'payrollRuns'])
            ->middleware(['can:payroll.view', 'can:payroll.salary.view'])
            ->name('payroll.runs.index');
        Route::post('/payroll/runs', [HrPayrollController::class, 'createPayrollRun'])
            ->middleware(['can:payroll.runs.manage', 'can:payroll.salary.view'])
            ->name('payroll.runs.store');
        Route::get('/payroll/runs/{run}', [HrPayrollController::class, 'payrollRun'])
            ->middleware(['can:payroll.view', 'can:payroll.salary.view'])
            ->name('payroll.runs.show');
        Route::post('/payroll/runs/{run}/approve', [HrPayrollController::class, 'approvePayrollRun'])
            ->middleware('can:payroll.approve')
            ->name('payroll.runs.approve');
        Route::post('/payroll/runs/{run}/pay', [HrPayrollController::class, 'payPayrollRun'])
            ->middleware(['can:payroll.approve', 'can:payroll.salary.view'])
            ->name('payroll.runs.pay');
        Route::get('/payroll/runs/{run}/accounting-export', [HrPayrollController::class, 'accountingExport'])
            ->middleware(['can:payroll.view', 'can:payroll.salary.view'])
            ->name('payroll.runs.accounting-export');

        Route::get('/inventory/overview', [InventoryProcurementController::class, 'overview'])
            ->middleware('can:inventory.view')
            ->name('inventory.overview');
        Route::post('/inventory/categories', [InventoryProcurementController::class, 'storeCategory'])
            ->middleware('can:inventory.manage')
            ->name('inventory.categories.store');
        Route::post('/inventory/suppliers', [InventoryProcurementController::class, 'storeSupplier'])
            ->middleware('can:inventory.manage')
            ->name('inventory.suppliers.store');
        Route::post('/inventory/products', [InventoryProcurementController::class, 'storeProduct'])
            ->middleware('can:inventory.manage')
            ->name('inventory.products.store');
        Route::match(['put', 'patch'], '/inventory/products/{product}', [InventoryProcurementController::class, 'updateProduct'])
            ->middleware('can:inventory.manage')
            ->name('inventory.products.update');
        Route::patch('/inventory/products/{product}/online-visibility', [InventoryProcurementController::class, 'setProductOnlineVisibility'])
            ->middleware('can:inventory.manage')
            ->name('inventory.products.online-visibility');
        Route::post('/inventory/products/{product}/images', [InventoryProcurementController::class, 'storeProductImages'])
            ->middleware('can:inventory.manage')
            ->name('inventory.products.images.store');
        Route::delete('/inventory/products/{product}/images/{image}', [InventoryProcurementController::class, 'deleteProductImage'])
            ->middleware('can:inventory.manage')
            ->name('inventory.products.images.destroy');
        Route::patch('/inventory/products/{product}/images/{image}/primary', [InventoryProcurementController::class, 'setPrimaryProductImage'])
            ->middleware('can:inventory.manage')
            ->name('inventory.products.images.primary');
        Route::post('/inventory/locations', [InventoryProcurementController::class, 'storeLocation'])
            ->middleware('can:inventory.manage')
            ->name('inventory.locations.store');
        Route::get('/inventory/movements', [InventoryProcurementController::class, 'movements'])
            ->middleware('can:inventory.view')
            ->name('inventory.movements.index');
        Route::post('/inventory/adjustments', [InventoryProcurementController::class, 'adjustStock'])
            ->middleware(['can:inventory.adjust', 'can:inventory.view'])
            ->name('inventory.adjustments.store');
        Route::post('/inventory/stocktakes', [InventoryProcurementController::class, 'createStocktake'])
            ->middleware('can:inventory.manage')
            ->name('inventory.stocktakes.store');
        Route::post('/inventory/stocktakes/{stocktake}/post', [InventoryProcurementController::class, 'postStocktake'])
            ->middleware(['can:inventory.adjust', 'can:inventory.view'])
            ->name('inventory.stocktakes.post');
        Route::get('/procurement/requisitions', [InventoryProcurementController::class, 'requisitions'])
            ->middleware('can:procurement.view')
            ->name('procurement.requisitions.index');
        Route::get('/procurement/overview', [InventoryProcurementController::class, 'procurementOverview'])
            ->middleware('can:procurement.view')
            ->name('procurement.overview');
        Route::post('/procurement/requisitions', [InventoryProcurementController::class, 'storeRequisition'])
            ->middleware('can:procurement.manage')
            ->name('procurement.requisitions.store');
        Route::patch('/procurement/requisitions/{requisition}/decision', [InventoryProcurementController::class, 'decideRequisition'])
            ->middleware('can:procurement.approve')
            ->name('procurement.requisitions.decision');
        Route::get('/procurement/purchase-orders', [InventoryProcurementController::class, 'purchaseOrders'])
            ->middleware('can:procurement.view')
            ->name('procurement.purchase-orders.index');
        Route::post('/procurement/purchase-orders', [InventoryProcurementController::class, 'createPurchaseOrder'])
            ->middleware('can:procurement.manage')
            ->name('procurement.purchase-orders.store');
        Route::post('/procurement/purchase-orders/{purchaseOrder}/approve', [InventoryProcurementController::class, 'decidePurchaseOrder'])
            ->middleware('can:procurement.approve')
            ->name('procurement.purchase-orders.approve');
        Route::post('/procurement/purchase-orders/{purchaseOrder}/receive', [InventoryProcurementController::class, 'receivePurchaseOrder'])
            ->middleware('can:procurement.receive')
            ->name('procurement.purchase-orders.receive');
        Route::post('/procurement/purchase-orders/{purchaseOrder}/supplier-invoices', [InventoryProcurementController::class, 'recordSupplierInvoice'])
            ->middleware('can:procurement.invoice.manage')
            ->name('procurement.supplier-invoices.store');
        Route::post('/inventory/transfers', [InventoryProcurementController::class, 'createTransfer'])
            ->middleware('can:inventory.manage')
            ->name('inventory.transfers.store');
        Route::post('/inventory/transfers/{transfer}/dispatch', [InventoryProcurementController::class, 'dispatchTransfer'])
            ->middleware(['can:inventory.adjust', 'can:inventory.view'])
            ->name('inventory.transfers.dispatch');
        Route::post('/inventory/transfers/{transfer}/receive', [InventoryProcurementController::class, 'receiveTransfer'])
            ->middleware(['can:inventory.adjust', 'can:inventory.view'])
            ->name('inventory.transfers.receive');

        Route::get('/pos/overview', [PosController::class, 'overview'])
            ->middleware('can:pos.view')
            ->name('pos.overview');
        Route::get('/pos/customers', [PosController::class, 'customers'])
            ->middleware('can:pos.sale.create')
            ->name('pos.customers.index');
        Route::get('/pos/pin/me', [PosPinController::class, 'me'])
            ->name('pos.pin.me');
        Route::post('/pos/pin', [PosPinController::class, 'store'])
            ->middleware('throttle:10,1,pos-pin-set')
            ->name('pos.pin.store');
        Route::get('/pos/pins', [PosPinController::class, 'index'])
            ->middleware('can:pos.pin.manage')
            ->name('pos.pins.index');
        Route::get('/pos/pins/audit', [PosPinController::class, 'audit'])
            ->middleware('can:pos.pin.manage')
            ->name('pos.pins.audit');
        Route::post('/pos/pins/{user}/reset', [PosPinController::class, 'reset'])
            ->middleware(['can:pos.pin.manage', 'throttle:20,1,pos-pin-admin'])
            ->name('pos.pins.reset');
        Route::post('/pos/pins/{user}/unlock', [PosPinController::class, 'unlock'])
            ->middleware(['can:pos.pin.manage', 'throttle:20,1,pos-pin-admin'])
            ->name('pos.pins.unlock');
        Route::get('/pos/shifts/current', [PosController::class, 'currentShift'])
            ->middleware('can:pos.view')
            ->name('pos.shifts.current');
        Route::get('/pos/shifts/active', [PosController::class, 'activeShifts'])
            ->middleware('can:pos.shift.view_all')
            ->name('pos.shifts.active');
        Route::get('/pos/shifts', [PosController::class, 'shifts'])
            ->middleware('can:pos.view')
            ->name('pos.shifts.index');
        Route::get('/pos/shifts/{shift}', [PosController::class, 'shiftDetail'])
            ->whereNumber('shift')
            ->middleware('can:pos.view')
            ->name('pos.shifts.show');
        Route::post('/pos/shifts', [PosController::class, 'openShift'])
            ->middleware(['can:pos.shift.manage', 'throttle:12,1,pos-shift-open'])
            ->name('pos.shifts.store');
        Route::post('/pos/shifts/{shift}/cash-movements', [PosController::class, 'recordCashMovement'])
            ->middleware('can:pos.shift.manage')
            ->name('pos.shifts.cash-movements.store');
        Route::post('/pos/shifts/{shift}/close', [PosController::class, 'closeShift'])
            ->middleware('can:pos.shift.manage')
            ->name('pos.shifts.close');
        Route::post('/pos/shifts/{shift}/adjust', [PosController::class, 'adjustShift'])
            ->middleware('can:pos.shift.adjust')
            ->name('pos.shifts.adjust');
        Route::post('/pos/shifts/{shift}/reopen', [PosController::class, 'reopenShift'])
            ->middleware('can:pos.shift.adjust')
            ->name('pos.shifts.reopen');
        Route::get('/pos/registers', [PosController::class, 'registers'])
            ->middleware('can:pos.view')
            ->name('pos.registers.index');
        Route::post('/pos/registers', [PosController::class, 'storeRegister'])
            ->middleware('can:pos.register.manage')
            ->name('pos.registers.store');
        Route::put('/pos/registers/{register}', [PosController::class, 'updateRegister'])
            ->whereNumber('register')
            ->middleware('can:pos.register.manage')
            ->name('pos.registers.update');
        Route::get('/pos/settings', [PosController::class, 'posSettings'])
            ->middleware('can:pos.settings.manage')
            ->name('pos.settings.show');
        Route::put('/pos/settings', [PosController::class, 'savePosSettings'])
            ->middleware('can:pos.settings.manage')
            ->name('pos.settings.update');
        Route::post('/pos/sales', [PosController::class, 'createSale'])
            ->middleware('can:pos.sale.create')
            ->name('pos.sales.store');
        Route::get('/pos/sales/my', [PosReportController::class, 'mySales'])
            ->middleware('can:pos.view')
            ->name('pos.sales.my');
        Route::get('/pos/sales/transactions', [PosReportController::class, 'transactions'])
            ->middleware('can:pos.sales.view_all')
            ->name('pos.sales.transactions');
        Route::get('/pos/sales/returns', [PosReportController::class, 'returns'])
            ->middleware('can:pos.view')
            ->name('pos.sales.returns');
        Route::get('/pos/sales/report', [PosReportController::class, 'salesReport'])
            ->middleware('can:pos.sales.view_all')
            ->name('pos.sales.report');
        Route::get('/pos/staff', [PosReportController::class, 'staff'])
            ->middleware('can:pos.view')
            ->name('pos.staff.index');
        Route::get('/payments/reconciliation', [PaymentController::class, 'reconciliation'])
            ->middleware('can:payments.reconcile')
            ->name('payments.reconciliation.index');
        Route::post('/payments/reconciliation/{transaction}/settlement', [PaymentController::class, 'recordSettlement'])
            ->middleware('can:payments.reconcile')
            ->name('payments.reconciliation.store');
        Route::get('/accounting/overview', [AccountingController::class, 'overview'])
            ->middleware('can:accounting.view')
            ->name('accounting.overview');
        Route::post('/accounting/accounts', [AccountingController::class, 'createAccount'])
            ->middleware('can:accounting.manage')
            ->name('accounting.accounts.store');
        Route::put('/accounting/posting-rules', [AccountingController::class, 'savePostingRule'])
            ->middleware('can:accounting.manage')
            ->name('accounting.posting-rules.update');
        Route::post('/accounting/periods', [AccountingController::class, 'createPeriod'])
            ->middleware('can:accounting.manage')
            ->name('accounting.periods.store');
        Route::post('/accounting/periods/{period}/close', [AccountingController::class, 'closePeriod'])
            ->middleware('can:accounting.period.close')
            ->name('accounting.periods.close');
        Route::post('/accounting/journals', [AccountingController::class, 'createJournal'])
            ->middleware('can:accounting.manage')
            ->name('accounting.journals.store');
        Route::post('/accounting/journals/{journal}/post', [AccountingController::class, 'postJournal'])
            ->middleware('can:accounting.approve')
            ->name('accounting.journals.post');
        Route::post('/accounting/journals/{journal}/reverse', [AccountingController::class, 'reverseJournal'])
            ->middleware('can:accounting.approve')
            ->name('accounting.journals.reverse');
        Route::post('/accounting/source-events/{event}/retry', [AccountingController::class, 'retrySourceEvent'])
            ->middleware('can:accounting.manage')
            ->name('accounting.source-events.retry');
        Route::get('/accounting/reports', [AccountingController::class, 'reports'])
            ->middleware('can:accounting.view')
            ->name('accounting.reports');
        Route::get('/pos/sales/{sale}/receipt', [PosController::class, 'receipt'])
            ->middleware('can:pos.receipt.view')
            ->name('pos.sales.receipt');
        Route::post('/pos/sales/{sale}/refund', [PosController::class, 'refund'])
            ->middleware('can:pos.refund.approve')
            ->name('pos.sales.refund');
        Route::post('/pos/sales/{sale}/void', [PosController::class, 'voidSale'])
            ->middleware('can:pos.sale.void')
            ->name('pos.sales.void');
    });
});
