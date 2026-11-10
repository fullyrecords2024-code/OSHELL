import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ChangeEvent, FormEvent, MouseEvent } from 'react'
import './App.css'
import { apiDownload, apiRequest, ApiError, setApiAccessToken } from './lib/api'
import { filterNavigationByPermissions } from './lib/authorization'
import type { PermissionAwareNavigationItem } from './lib/authorization'

type AuthUser = {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  customer_id: number | null
  organization_id: number | null
  branch_id: number | null
  is_active: boolean
  password_reset_required: boolean
  roles: string[]
  permissions: string[]
  branch_assignments: Branch[]
}

type Branch = {
  id: number
  organization_id?: number
  name: string
  code: string
  city?: string | null
  country?: string | null
  timezone?: string | null
  status?: string | null
  email?: string | null
  phone?: string | null
  address?: string | null
  is_active: boolean
  departments_count?: number
}

type BranchAssignment = Branch & {
  pivot?: {
    branch_id?: number
    effective_from?: string
    effective_until?: string | null
    is_primary?: boolean
  }
}

type Department = {
  id: number
  organization_id?: number
  name: string
  code: string
  description?: string | null
  status?: string | null
  branch_id: number | null
  branch?: Branch | null
  is_active: boolean
}

type Overview = {
  organization: {
    name: string
    business_type?: string | null
    branches_count: number
    departments_count: number
  } | null
  stats: {
    organizations: number
    branches: number
    departments: number
    users: number | null
  }
  branches: Branch[]
  departments: Department[]
}

type OrganizationRecord = {
  id: number
  name: string
  slug: string
  business_type: string | null
  country_code: string | null
  currency_code: string | null
  timezone: string | null
  status?: string | null
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
  registration_number: string | null
  is_active: boolean
}

type EmailSettingsRecord = {
  driver: string
  host: string | null
  port: number
  encryption: 'starttls' | 'ssl'
  username: string | null
  password_set: boolean
  from_address: string | null
  from_name: string | null
  source: 'environment' | 'settings'
  updated_at: string | null
}

type NotificationSettingsRecord = {
  groups: { key: string; label: string; enabled: boolean }[]
  updated_at: string | null
}

type InboxNotification = {
  id: string
  title: string
  message: string
  group: string
  group_label: string
  severity: string
  url: string | null
  audience: string | null
  reply_to: string | null
  reply_to_name: string | null
  occurred_at: string | null
  read_at: string | null
  created_at: string | null
  folder?: 'inbox' | 'sent'
  recipient_email?: string | null
  recipient_name?: string | null
  status?: string
}

type InboxResponse = {
  data: InboxNotification[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  unread_count: number
}

type MailboxResponse = InboxResponse & {
  folder: 'inbox' | 'sent'
  categories: { key: string; label: string }[]
}

type AuthSessionRecord = {
  id: number
  name: string
  last_used_at: string | null
  created_at: string
  expires_at: string | null
  current: boolean
}

type ApiUser = {
  id: number
  name: string
  email: string
  is_active: boolean
  roles: { name: string }[]
  branch_assignments: BranchAssignment[]
}

type RoleList = {
  roles: { id: number; name: string; permissions: { id: number; name: string }[] }[]
  permissions: { id: number; name: string }[]
}

type AuditEvent = {
  id: number
  action: string
  subject_type: string | null
  subject_id: string | null
  status_code: number | null
  occurred_at: string
  user?: { name: string } | null
  branch?: Branch | null
}

type WebsitePage = {
  id: number
  slug: string
  page_type: 'page' | 'journal'
  title: string
  eyebrow: string | null
  summary: string | null
  body: string | null
  seo_title: string | null
  seo_description: string | null
  cover_image_url?: string | null
  status: 'draft' | 'published' | 'archived'
  published_at: string | null
  updated_at: string
}

type PublicPageKey = 'home' | 'about' | 'services' | 'shop' | 'rewards' | 'testimonials' | 'journal' | 'contact' | 'custom'
const publicSitePages: PublicPageKey[] = ['home', 'about', 'services', 'shop', 'rewards', 'testimonials', 'journal', 'contact']

type PublicRoute = {
  page: PublicPageKey
  organizationSlug: string
  customPageSlug: string | null
  journalArticleSlug: string | null
  detailType: 'product' | 'service' | null
  detailId: number | null
  detailSlug?: string | null
}

function readPublicRoute(): PublicRoute {
  const hashRoute = window.location.hash.startsWith('#/')
    ? window.location.hash.slice(2).split('/').filter(Boolean)
    : []
  const segments = hashRoute.length
    ? hashRoute
    : window.location.pathname.split('/').filter(Boolean)
  const queryOrganization = new URLSearchParams(window.location.search).get('organization_slug') ?? ''

  if (!segments.length) {
    return { page: 'home', organizationSlug: queryOrganization, customPageSlug: null, journalArticleSlug: null, detailType: null, detailId: null }
  }

  if (segments.length === 1 && publicSitePages.includes(segments[0] as PublicPageKey)) {
    return {
      page: segments[0] as PublicPageKey,
      organizationSlug: queryOrganization,
      customPageSlug: null,
      journalArticleSlug: null,
      detailType: null,
      detailId: null,
    }
  }

  const organizationSlug = segments[0]
  const routeSegment = segments[1]
  if (!routeSegment) {
    return { page: 'home', organizationSlug, customPageSlug: null, journalArticleSlug: null, detailType: null, detailId: null }
  }
  if (routeSegment === 'journal' && segments[2]) {
    return { page: 'journal', organizationSlug, customPageSlug: null, journalArticleSlug: segments[2], detailType: null, detailId: null }
  }
  if ((routeSegment === 'shop' || routeSegment === 'services') && segments[2]) {
    const numeric = /^\d+$/.test(segments[2])
    return {
      page: routeSegment,
      organizationSlug,
      customPageSlug: null,
      journalArticleSlug: null,
      detailType: routeSegment === 'shop' ? 'product' : 'service',
      detailId: numeric ? Number(segments[2]) : null,
      detailSlug: numeric ? null : decodeURIComponent(segments[2]),
    }
  }
  if (publicSitePages.includes(routeSegment as PublicPageKey)) {
    return { page: routeSegment as PublicPageKey, organizationSlug, customPageSlug: null, journalArticleSlug: null, detailType: null, detailId: null }
  }

  return { page: 'custom', organizationSlug, customPageSlug: routeSegment, journalArticleSlug: null, detailType: null, detailId: null }
}

function publicProductPath(organizationSlug: string, productId: number, slug?: string | null): string {
  const prefix = organizationSlug ? `/${encodeURIComponent(organizationSlug)}` : ''
  return `${prefix}/shop/${encodeURIComponent(slug || String(productId))}`
}

function publicServicePath(organizationSlug: string, serviceId: number, slug?: string | null): string {
  const prefix = organizationSlug ? `/${encodeURIComponent(organizationSlug)}` : ''
  return `${prefix}/services/${encodeURIComponent(slug || String(serviceId))}`
}

function publicPathFor(
  organizationSlug: string,
  page: PublicPageKey = 'home',
  contentSlug: string | null = null,
): string {
  const prefix = organizationSlug ? `/${encodeURIComponent(organizationSlug)}` : ''
  if (page === 'home') return prefix || '/'
  if (page === 'custom') return `${prefix}/${encodeURIComponent(contentSlug ?? '')}`
  if (page === 'journal' && contentSlug) {
    return `${prefix}/journal/${encodeURIComponent(contentSlug)}`
  }

  return `${prefix}/${page}`
}

function storedAnalyticsConsent(organizationSlug: string): boolean {
  if (!organizationSlug) return false
  try {
    return JSON.parse(window.localStorage.getItem(`oshel.privacy.${organizationSlug}`) ?? 'null')?.analyticsAllowed === true
  } catch {
    return false
  }
}

function hasStoredPrivacyDecision(organizationSlug: string): boolean {
  return Boolean(organizationSlug && window.localStorage.getItem(`oshel.privacy.${organizationSlug}`) !== null)
}

const skincarePhotoSlides = [
  { src: '/images/public-site/hero-clay-mask.jpg', alt: 'A guest relaxing during a soothing clay mask treatment', caption: 'Slow down. Let your skin reset.' },
  { src: '/images/public-site/hero-facial-massage.jpg', alt: 'A therapist gently applying a facial treatment', caption: 'The quiet art of facial care.' },
  { src: '/images/public-site/hero-clinic-treatment.jpg', alt: 'A professional facial treatment at a skincare clinic', caption: 'Professional care, thoughtfully delivered.' },
  { src: '/images/public-site/hero-skincare-mask.jpg', alt: 'A guest relaxing with a nourishing skincare mask', caption: 'Make room for a brighter ritual.' },
] as const

type WebsiteGrowthData = {
  loyalty?: {
    program: { points_per_100_currency: number; points_per_currency_redemption: number; minimum_redemption_points: number; tiers: string | null; is_active: boolean } | null
    accounts: { customer_id: number; name: string; email: string | null; points_balance: number; tier: string }[]
    recent_entries: { id: number; event_type: string; points_delta: number; reason: string | null; created_at: string }[]
  }
  reviews?: Paginated<{ id: number; rating: number; display_name: string; review_text: string; status: string; featured: boolean; customer_name: string | null; product_name: string | null; service_name: string | null }>
  sections?: { id: number; slug: string; title: string; description: string | null; sort_order: number; is_active: boolean; product_ids: number[] }[]
  promotions?: Paginated<{ id: number; title: string; message: string | null; coupon_code: string | null; discount_type: string | null; discount_value: number | string | null; minimum_subtotal: number | string; maximum_redemptions: number | null; starts_at: string | null; ends_at: string | null; status: string; show_publicly: boolean; redemption_count: number }>
  socialLinks?: { platform: string; url: string; is_active: boolean }[]
  products?: Pick<InventoryProduct, 'id' | 'sku' | 'name' | 'is_public'>[]
}

type MarketingCenterData = {
  integrations?: {
    platforms: {
      platform: string
      name: string
      authorization: 'available' | 'manual_only'
      authorization_reason: string | null
      connection: { id: number; status: string; external_account_name: string | null; connected_at: string | null } | null
      capabilities: {
        campaign_import: string
        campaign_metrics_sync: string
        campaign_publish: string
        content_publish: string
        analytics_sync: string
        reason: string
      }
    }[]
    sync_logs: { id: number; platform: string; operation: string; status: string; error_code: string | null; summary: string | null; created_at: string }[]
  }
  campaigns?: {
    campaigns: {
      id: number; branch_id: number | null; platform: string; name: string; objective: string | null; status: string
      budget_amount: string | null; spend_amount: string | null; currency_code: string
      impressions: number | null; clicks: number | null; conversions: number | null; source: string
      metrics_recorded_at: string | null; updated_at: string
    }[]
    sync_logs: { id: number; platform: string; operation: string; status: string; summary: string | null; created_at: string }[]
  }
  posts?: {
    id: number; branch_id: number | null; title: string; caption: string; channels: string[] | string; status: string
    scheduled_at: string | null; external_url: string | null; review_note: string | null
    submitted_by: number | null; approved_by: number | null; created_at: string
  }[]
  seo?: {
    summary: { pages_audited: number; pages_needing_attention: number; tracked_keywords: number; branch_profiles_configured: number }
    pages: { id: number; slug: string; title: string; status: string; audit_status: string; issues: { code: string; severity: string; message: string }[] }[]
    branches: { id: number; name: string; city: string | null; seo: { page_title: string | null; meta_description: string | null; target_keywords: string[] } | null }[]
    keywords: { id: number; branch_id: number | null; keyword: string; target_path: string; priority: string; manual_position: number | null; position_recorded_on: string | null; source: string }[]
    keyword_source: string
    keyword_notice: string
  }
}

type Paginated<T> = {
  data: T[]
  total: number
  current_page?: number
  last_page?: number
  per_page?: number
}

type CustomerRecord = {
  id: number
  name: string
  email: string | null
  phone: string | null
  preferred_channel: string
  status: string
  registration_source: string
  registered_branch_id: number | null
  registered_branch?: Pick<Branch, 'id' | 'name' | 'code'> | null
  branch_history: {
    id: number
    activity: string
    occurred_at: string
    branch: Pick<Branch, 'id' | 'name' | 'code'> | null
  }[]
  patient_profile_linked?: boolean
  patient_profile?: {
    patient_number: string
    date_of_birth: string | null
    emergency_contact_name: string | null
    emergency_contact_phone: string | null
    emergency_contact_relationship: string | null
    is_active: boolean
  } | null
  consents?: {
    id: number
    consent_type: string
    status: string
    capture_method: string
    branch_id: number | null
    captured_at: string
    expires_at: string | null
    document_available: boolean
  }[]
  portal_linked?: boolean
  portal_active?: boolean
  created_at?: string
  updated_at?: string
}

type CustomerActivityRecord = {
  customer_id: number
  patient_profile_linked: boolean
  capabilities: {
    appointments: boolean
    orders: boolean
    pos_sales: boolean
    payments: boolean
    clinical: boolean
    loyalty: boolean
    manage: {
      book_appointments: boolean
      appointment_status: boolean
      appointment_deposits: boolean
      orders: boolean
      returns: boolean
      pos_refunds: boolean
      clinical: boolean
      clinical_amendments: boolean
      loyalty: boolean
      payments: boolean
    }
  }
  summary: {
    appointments_total: number
    appointments_upcoming: number
    orders_total: number
    orders_paid_total: number
    pos_sales_total: number
    pos_spend_total: number
    payments_total: number
    payments_paid_total: number
    clinical_encounters_total: number
    treatment_plans_total: number
    loyalty_points: number
  }
  appointments: {
    id: number
    appointment_number: string
    branch_id: number
    branch_name: string | null
    service_id: number
    service_name: string | null
    status: string
    booking_channel: string
    starts_at: string
    ends_at: string
    service_price: string
    currency_code: string
    deposit_due: string
    deposit_status: string
  }[]
  orders: {
    id: number
    order_number: string
    branch_name: string | null
    status: string
    payment_status: string
    fulfillment_type: string
    currency_code: string
    total_amount: string
    return_status: string
    return_reason: string | null
    paid_at: string | null
    created_at: string
    lines: {
      name_snapshot: string
      sku_snapshot: string
      quantity: string
      unit_price: string
      line_total: string
    }[]
  }[]
  pos_sales: {
    id: number
    receipt_number: string
    branch_name: string | null
    status: string
    currency_code: string
    total_amount: string
    refunded_amount: string
    loyalty_points_redeemed: number
    cashier_name: string | null
    sold_at: string
    lines: {
      item_type: string
      item_name: string
      item_code: string | null
      quantity: string
      line_total: string
    }[]
    payments: {
      tender_type: string
      amount: string
      reference: string | null
      recorded_at: string
    }[]
  }[]
  payments: {
    key: string
    transaction_id: number | null
    source: string
    source_label: string
    reference: string | null
    order_number: string | null
    receipt_number: string | null
    provider: string
    method: string
    status: string
    currency_code: string
    amount: string
    settled_at: string | null
    occurred_at: string
  }[]
  clinical_encounters: {
    id: number
    branch_id: number
    branch_name: string | null
    appointment_number: string | null
    status: string
    vitals: unknown
    assessment: unknown
    clinical_note: string | null
    treatment_plan: string | null
    follow_up_at: string | null
    follow_up_notes: string | null
    started_at: string
    completed_at: string | null
    clinician_name: string | null
  }[]
  loyalty: {
    account: { id: number; points_balance: number; tier: string; created_at: string } | null
    entries: {
      event_type: string
      points_delta: number
      source_type: string
      reason: string | null
      created_at: string
    }[]
  } | null
}

type CustomerActivityTab = 'orders' | 'pos' | 'payments' | 'appointments' | 'clinical' | 'loyalty'
type CustomerActivityAppointment = CustomerActivityRecord['appointments'][number]
type CustomerActivityOrder = CustomerActivityRecord['orders'][number]
type CustomerActivitySale = CustomerActivityRecord['pos_sales'][number]
type CustomerActivityPayment = CustomerActivityRecord['payments'][number]
type CustomerActivityEncounter = CustomerActivityRecord['clinical_encounters'][number]
type ClinicalAssessmentTemplateRecord = {
  id: number
  name: string
  description: string | null
  fields: {
    key: string
    label: string
    type: 'text' | 'number' | 'boolean' | 'select'
    required?: boolean
    options?: string[]
  }[]
}

type ServiceRecord = {
  id: number
  slug?: string | null
  name: string
  description: string | null
  classification: string
  category: { id: number; name: string } | null
  category_id?: number | null
  duration_minutes: number
  buffer_minutes: number
  is_active?: boolean
  images?: CatalogueImage[]
  primary_image_url?: string | null
  branches: {
    id: number
    name: string
    price: string
    currency_code: string
    is_public?: boolean
    is_available?: boolean
    deposit_policy: string
    deposit_amount: string
    booking_lead_hours: number
    cancellation_notice_hours: number
  }[]
}

type BranchBusinessHour = {
  id?: number
  day_of_week: number
  is_closed: boolean
  opens_at: string | null
  closes_at: string | null
}

type BookingResourceRecord = {
  id: number
  branch_id: number
  user_id: number | null
  name: string
  resource_type: 'staff' | 'room' | 'equipment'
  schedules: {
    id: number
    day_of_week: number
    is_unavailable: boolean
    starts_at: string | null
    ends_at: string | null
  }[]
}

type ResourceScheduleDraft = {
  resourceId: number
  schedules: {
    day_of_week: number
    is_unavailable: boolean
    starts_at: string | null
    ends_at: string | null
  }[]
}

type AppointmentRecord = {
  id: number
  appointment_number: string
  status: string
  booking_channel: string
  starts_at: string
  ends_at: string
  service_price: string
  currency_code: string
  deposit_due: string
  deposit_status: string
  branch: Pick<Branch, 'id' | 'name' | 'code'>
  service: Pick<ServiceRecord, 'id' | 'name' | 'duration_minutes'>
  customer: Pick<CustomerRecord, 'id' | 'name' | 'phone'>
  resources: { id: number; name: string; resource_type: string }[]
  queue_entry?: { id: number; queue_position: number; status: string } | null
}

type EmployeeRecord = {
  id: number
  employee_number: string
  job_title: string
  employment_type: string
  status: string
  hired_on: string
  branch_id: number
  name: string
  email: string
}

type PayrollRunRecord = {
  id: number
  branch_id: number
  period_starts_on: string
  period_ends_on: string
  status: 'draft' | 'approved' | 'paid'
  gross_total: number | string
  deduction_total: number | string
  net_total: number | string
  created_by: number
}

type InventoryProduct = {
  id: number
  sku: string
  name: string
  barcode?: string | null
  brand?: string | null
  description?: string | null
  category_id?: number | null
  tax_category?: string | null
  unit_of_measure: string
  selling_price: string | number
  reorder_level: string | number
  tracks_batches: boolean
  tracks_expiry: boolean
  is_public: boolean
  is_active: boolean
  category_name?: string | null
  images?: CatalogueImage[]
  primary_image_url?: string | null
}

type InventoryOverview = {
  products: Paginated<InventoryProduct>
  branches: Pick<Branch, 'id' | 'name' | 'code' | 'is_active'>[]
  categories: { id: number; name: string }[]
  suppliers: { id: number; name: string; currency_code: string }[]
  locations: { id: number; branch_id: number; name: string; code: string }[]
  balances: {
    id: number
    location_id: number
    product_id: number
    branch_id: number
    location_name: string
    product_name: string
    sku: string
    quantity: number | string
    average_unit_cost: number | string
    valuation: number | string
  }[]
  reorder_alerts: { location_name: string; sku: string; product_name: string; quantity: number | string; reorder_level: number | string }[]
  batches: { batch_id: number; lot_code: string; expires_on: string | null; quantity: number | string; product_name: string; location_name: string }[]
  transfers: {
    id: number
    reference: string
    status: string
    created_by: number
    from_location: string
    to_location: string
    lines: { product_name: string; sku: string; quantity: number | string }[]
  }[]
  stocktakes: { id: number; reference: string; status: string; created_by: number; location_name: string }[]
  in_transit: { reference: string; quantity: number | string; unit_cost: number | string; product_name: string; from_location: string }[]
  in_transit_valuation: number | string
  movements: { id: number; movement_type: string; quantity: number | string; unit_cost: number | string; total_value: number | string; product_name: string; location_name: string; occurred_at: string }[]
  costing_method: string
}

type ProcurementLine = {
  id: number
  product_id: number
  product_name: string
  sku: string
  quantity?: number | string
  quantity_ordered?: number | string
  quantity_received?: number | string
  unit_cost?: number | string
  tracks_batches?: boolean
  tracks_expiry?: boolean
}

type ProcurementOverview = {
  requisitions: {
    id: number
    reference: string
    status: string
    supplier_id: number | null
    supplier_name: string | null
    requested_by: number
    lines: ProcurementLine[]
  }[]
  purchase_orders: {
    id: number
    reference: string
    status: string
    supplier_id: number
    supplier_name: string
    created_by: number
    currency_code: string
    lines: ProcurementLine[]
    grns: { id: number; reference: string; received_at: string; total_amount: number }[]
  }[]
  supplier_invoices: { id: number; invoice_number: string; supplier_name: string; total_amount: number | string; due_date: string | null }[]
}

type PosProduct = {
  id: number
  sku: string
  barcode: string | null
  name: string
  unit_of_measure: string
  selling_price: string | number
  tracks_batches: boolean
  category_name: string | null
  quantity: string | number
}

type PosService = {
  id: number
  name: string
  classification: string
  duration_minutes: number
  category_name: string | null
  price: string | number
  currency_code: string
}

type PosReceipt = {
  id: number
  branch_id: number
  branch_name: string
  branch_code: string
  cashier_name: string
  receipt_number: string
  customer_name_snapshot: string | null
  status: string
  currency_code: string
  subtotal: string | number
  discount_amount: string | number
  tax_amount: string | number
  total_amount: string | number
  sold_at: string
  lines: {
    item_type: string
    item_code: string | null
    item_name: string
    quantity: string | number
    unit_price: string | number
    line_total: string | number
  }[]
  payments: { tender_type: string; amount: string | number; reference: string | null }[]
  copy?: boolean
  replayed?: boolean
}

type PosOverview = {
  branches: Pick<Branch, 'id' | 'name' | 'code'>[]
  branch_id: number | null
  location_id: number | null
  locations: { id: number; name: string; code: string }[]
  categories: string[]
  products: PosProduct[]
  services: PosService[]
  shift: {
    id: number
    branch_id: number
    terminal_name: string
    opening_float: number | string
    opened_at: string
  } | null
  metrics: { sales_total: number | string; sales_count: number; customers_count: number }
  recent_shifts: {
    id: number
    terminal_name: string
    status: string
    opening_float: number | string
    expected_cash: number | string | null
    counted_cash: number | string | null
    cash_variance: number | string | null
    opened_at: string
    closed_at: string | null
    cashier_name: string
  }[]
  recent_sales: {
    id: number
    receipt_number: string
    customer_name_snapshot: string | null
    total_amount: number | string
    status: string
    sold_at: string
  }[]
}

type PosPinStatus = {
  enrolled: boolean
  locked: boolean
  locked_until: string | null
  last_changed_at: string | null
  last_verified_at: string | null
  failed_attempts: number
  eligible: boolean
}

type PosRegister = {
  id: number
  branch_id: number
  name: string
  code: string
  is_active: boolean
}

type PosShiftSummary = {
  id: number
  status: string
  operator_id: number
  operator_name: string
  branch_id: number
  branch_name: string
  register_id: number | null
  register_name: string | null
  terminal_name: string
  opening_float: string | number
  opened_at: string
  closed_at: string | null
  pin_verified_at: string | null
  expected_cash: string | number | null
  counted_cash: string | number | null
  cash_variance: string | number | null
  variance_reason: string | null
  closed_by: number | null
  approved_by: number | null
  totals: {
    sales_total: string | number
    sales_count: number
    cash_sales: string | number
    mpesa_sales: string | number
    card_sales: string | number
    refunds_total: string | number
    refunds_count: number
    voids_total: string | number
    voids_count: number
    cash_in: string | number
    cash_out: string | number
    expected_cash: string | number
  }
}

type PosShiftRow = PosShiftSummary & {
  sales_total?: string | number
  sales_count?: number
  refunds_total?: string | number
  voids_total?: string | number
}

type PosSalesSummary = {
  gross_sales: string
  discounts: string
  net_sales: string
  refunds: string
  refund_count: number
  voids: string
  void_count: number
  transaction_count: number
  average_transaction_value: string
  product_sales: string
  service_sales: string
  cash_collected: string
  outstanding: string
  payments: { cash: string; mpesa: string; card: string }
  refunds_by_method: { cash: string; mpesa: string; card: string }
}

type PosTransactionRow = {
  id: number
  receipt_number: string
  status: string
  customer_name: string | null
  cashier_name: string
  branch_name: string
  register_name: string | null
  shift_id: number
  payment_methods: string[]
  subtotal: string
  discount_amount: string
  total_amount: string
  refunded_amount: string
  sold_at: string
}

type PosStaffSalesRow = {
  staff_id: number
  staff_name: string
  transaction_count: number
  gross_sales: string
  discounts: string
  refunds: string
  net_sales: string
}

type PosSalesReport = {
  summary: PosSalesSummary
  by_staff: PosStaffSalesRow[]
  recent: PosTransactionRow[]
}

type PosReturnRow = {
  id: number
  refund_number: string
  amount: string
  tender_type: string
  reason: string
  refunded_at: string
  sale_id: number
  receipt_number: string
  sale_status: string
  approved_by_name: string
  branch_name: string
}

type PosPinStaff = PosPinStatus & {
  id: number
  name: string
  email: string | null
  roles: string[]
  is_active: boolean
  pos_eligible: boolean
}

type PosPinAuditRow = {
  id: number
  user_id: number | null
  action: string
  subject_id: string | null
  properties: Record<string, unknown>
  ip_address: string | null
  occurred_at: string
}

type PosSettingsRecord = {
  variance_approval_threshold: number
  pin_max_attempts: number
  pin_lock_minutes: number
}

type PosShiftDetail = PosShiftSummary & {
  movements: { id: number; movement_type: string; amount: string; reason: string; recorded_by_name: string; occurred_at: string }[]
  sales: { id: number; receipt_number: string; customer_name_snapshot: string | null; total_amount: string; refunded_amount: string; status: string; sold_at: string }[]
  adjustments: { id: number; action: string; reason: string; adjusted_by_name: string; created_at: string }[]
}

type CatalogueImage = {
  id: number
  url: string
  is_primary: boolean
}

type StoreProduct = {
  id: number
  slug?: string | null
  sku: string
  name: string
  brand: string | null
  description: string | null
  unit_of_measure: string
  selling_price: string | number
  category_name: string | null
  available_quantity: string | number
  images?: CatalogueImage[]
  primary_image_url?: string | null
}

type EcommerceOrder = {
  id: number
  order_number: string
  branch_id: number
  branch_name?: string
  customer_name?: string
  status: string
  payment_status: string
  fulfillment_type: 'collection' | 'delivery'
  currency_code: string
  subtotal: string | number
  delivery_fee: string | number
  total_amount: string | number
  reservation_expires_at: string
  return_status: string
  return_reason?: string | null
  created_at: string
  lines: { sku?: string; name: string; quantity: string | number; line_total: string | number }[]
}

type CustomerPaymentOption = {
  id: 'mpesa' | 'pesapal'
  label: string
  configured: boolean
}

type CustomerPayment = {
  id: number
  provider: 'mpesa' | 'pesapal'
  method: 'mpesa' | 'card'
  status: string
  amount: string | number
  currency_code: string
  redirect_url: string | null
  merchant_reference: string
  message: string
}

type PaymentReconciliationRow = {
  id: number
  branch_id: number
  branch_name: string
  provider: string
  method: string
  status: string
  currency_code: string
  amount: string | number
  provider_reference: string | null
  provider_transaction_id: string | null
  merchant_reference: string
  clearing_account_code: string | null
  settled_at: string | null
  created_at: string
  order_number: string | null
  receipt_number: string | null
  statement_reference: string | null
  settled_amount: string | number | null
  fee_amount: string | number | null
  reconciliation_status: string | null
}

type LedgerAccount = {
  id: number
  code: string
  name: string
  account_type: 'asset' | 'liability' | 'equity' | 'income' | 'expense'
  currency_code: string | null
  is_active: boolean
}

type AccountingPeriod = {
  id: number
  name: string
  starts_on: string
  ends_on: string
  status: 'open' | 'closed'
}

type AccountingJournal = {
  id: number
  journal_number: string
  journal_date: string
  currency_code: string
  memo: string
  status: 'draft' | 'posted' | 'reversed'
  created_by: number | null
  approved_by: number | null
  branch_name: string | null
  reversal_of: number | null
}

type AccountingWorkspace = {
  accounts: LedgerAccount[]
  posting_rules: {
    id: number
    event_type: string
    is_active: boolean
    debit_account_id: number
    credit_account_id: number
    debit_account_code: string
    debit_account_name: string
    credit_account_code: string
    credit_account_name: string
  }[]
  supported_event_types: string[]
  periods: AccountingPeriod[]
  journals: AccountingJournal[]
  pending_source_events: {
    id: number
    event_type: string
    source_type: string
    source_id: number
    amount: string | number
    currency_code: string
    occurred_on: string
    description: string
    failure_reason: string | null
  }[]
}

type AccountingReport = {
  report: string
  from: string
  to: string
  data: {
    account_id: number
    code: string
    name: string
    account_type: string
    currency_code: string
    debit_total: string
    credit_total: string
    debit_balance: string
    credit_balance: string
  }[]
  totals: { currency_code: string; debit: string; credit: string }[]
}

type CrmLead = {
  id: number
  branch_id: number | null
  customer_id: number | null
  campaign_id: number | null
  assigned_to: number | null
  source: string
  status: string
  name: string
  email: string | null
  phone: string | null
  subject: string | null
  message: string | null
  contact_consent: boolean
  utm_source: string | null
  utm_medium: string | null
  utm_campaign: string | null
  created_at: string
  branch_name: string | null
  assigned_name: string | null
}

type CrmWorkspace = {
  summary: { new_leads: number; open_tasks: number; pending_campaigns: number; pending_expenses: number; conversions: number }
  leads: CrmLead[]
  tasks: { id: number; branch_id: number | null; lead_id: number | null; customer_id: number | null; campaign_id: number | null; assigned_to: number; title: string; details: string | null; priority: string; status: string; due_at: string | null; completed_at: string | null; assigned_name: string; branch_name: string | null }[]
  campaigns: { id: number; branch_id: number | null; segment_id: number | null; team_id: number | null; name: string; objective: string | null; channels: string; budget_amount: string | null; currency_code: string; starts_on: string | null; ends_on: string | null; status: string; created_by: number | null; submitted_by: number | null; approved_by: number | null; approved_at: string | null; segment_name: string | null; branch_name: string | null }[]
  teams: { id: number; name: string; description: string | null }[]
  segments: { id: number; branch_id: number | null; name: string; criteria: string }[]
  expenses: { id: number; branch_id: number | null; campaign_id: number | null; category: string; description: string; vendor: string | null; amount: string; currency_code: string; incurred_on: string; status: string; submitted_by: number; reviewed_by: number | null; review_note: string | null; reviewed_at: string | null; campaign_name: string | null; submitted_by_name: string }[]
  attribution: { source: string; conversions: number }[]
  members: { id: number; name: string }[]
  branches: Pick<Branch, 'id' | 'name'>[]
}

type StorefrontResponse = {
  organizations?: { name: string; slug: string }[]
  organization?: { name: string; slug: string; currency_code?: string }
  branches: Pick<Branch, 'id' | 'name' | 'code'>[]
  branch_id?: number
  products?: StoreProduct[]
}

type ClinicalEncounterRecord = {
  id: number
  status: 'in_progress' | 'completed'
  started_at: string
  completed_at: string | null
  clinical_note: string | null
  treatment_plan: string | null
  vitals: Record<string, string | number | null> | null
  assessment: Record<string, string | number | boolean | null> | null
  follow_up_at: string | null
  follow_up_notes: string | null
  customer: Pick<CustomerRecord, 'id' | 'name'>
  branch: Pick<Branch, 'id' | 'name'>
  template?: { id: number; name: string } | null
  amendments?: { id: number; reason: string; created_at: string; user?: { name: string } | null }[]
}

type AnalyticsDashboard = {
  period: { from: string; to: string }
  branch_id: number | null
  branches: { id: number; name: string }[]
  sections: {
    key: string
    title: string
    metrics: {
      branch_id: number | null
      label: string
      value: number
      unit: string
      currency_code?: string
      transactions?: number
      quantity?: number
      runs?: number
      spend?: number
      impressions?: number
      clicks?: number
      conversions?: number
    }[]
    definition: string
    source: string
    date_basis: string
    permission: string
  }[]
  metric_definitions: {
    key: string
    title: string
    definition: string
    source: string
    date_basis: string
    permission: string
  }[]
}

type PageKey = 'overview' | 'inbox' | 'analytics' | 'branches' | 'departments' | 'users' | 'roles' | 'audit' | 'customers' | 'services' | 'appointments' | 'clinical' | 'hr' | 'payroll' | 'inventory' | 'procurement' | 'pos' | 'pos-management' | 'ecommerce' | 'payments' | 'accounting' | 'crm' | 'website' | 'growth' | 'marketing' | 'organization' | 'email' | 'notifications' | 'account'

type StaffSection = {
  key: string
  label: string
  requiredPermissions?: string[]
}

const staffSections: Partial<Record<PageKey, StaffSection[]>> = {
  appointments: [
    { key: 'bookings', label: 'Bookings' },
    { key: 'queue', label: 'Queue', requiredPermissions: ['queue.view'] },
    { key: 'waitlist', label: 'Waitlist', requiredPermissions: ['waitlist.view'] },
  ],
  services: [
    { key: 'catalogue', label: 'Catalogue' },
    { key: 'schedules', label: 'Weekly schedules' },
  ],
  inventory: [
    { key: 'products', label: 'Products' },
    { key: 'stock', label: 'Stock levels' },
    { key: 'batches', label: 'Lots & expiry' },
    { key: 'movements', label: 'Movement ledger' },
    { key: 'transfers', label: 'Branch transfers' },
    { key: 'stocktakes', label: 'Stocktakes' },
    { key: 'setup', label: 'Catalogue setup' },
  ],
  procurement: [
    { key: 'requisitions', label: 'Requisitions' },
    { key: 'orders', label: 'Purchase orders' },
    { key: 'invoices', label: 'Supplier invoices' },
  ],
  pos: [
    { key: 'terminal', label: 'POS terminal', requiredPermissions: ['pos.view'] },
    { key: 'open', label: 'Open shift', requiredPermissions: ['pos.shift.manage'] },
    { key: 'current', label: 'Current shift', requiredPermissions: ['pos.view'] },
    { key: 'close', label: 'Close shift', requiredPermissions: ['pos.shift.manage'] },
    { key: 'history', label: 'Shift history', requiredPermissions: ['pos.shift.view_all'] },
    { key: 'my-sales', label: 'My sales', requiredPermissions: ['pos.view'] },
  ],
  'pos-management': [
    { key: 'shifts', label: 'All shifts', requiredPermissions: ['pos.shift.view_all'] },
    { key: 'active', label: 'Active shifts', requiredPermissions: ['pos.shift.view_all'] },
    { key: 'staff-sales', label: 'Staff sales', requiredPermissions: ['pos.sales.view_all'] },
    { key: 'transactions', label: 'Sales transactions', requiredPermissions: ['pos.sales.view_all'] },
    { key: 'returns', label: 'Refunds & voids', requiredPermissions: ['pos.refund.approve'] },
    { key: 'registers', label: 'Registers', requiredPermissions: ['pos.register.manage'] },
    { key: 'settings', label: 'POS settings', requiredPermissions: ['pos.settings.manage'] },
    { key: 'pins', label: 'PIN management', requiredPermissions: ['pos.pin.manage'] },
  ],
  accounting: [
    { key: 'accounts', label: 'Chart of accounts' },
    { key: 'rules', label: 'Posting rules' },
    { key: 'periods', label: 'Periods' },
    { key: 'journal', label: 'Journal entry' },
    { key: 'postings', label: 'Posting queue' },
    { key: 'reports', label: 'Reports' },
  ],
  crm: [
    { key: 'leads', label: 'Leads' },
    { key: 'segments', label: 'Segments' },
    { key: 'campaigns', label: 'Campaigns' },
    { key: 'tasks', label: 'Tasks' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'attribution', label: 'Attribution' },
  ],
  hr: [
    { key: 'employees', label: 'Employees' },
    { key: 'shifts', label: 'Shifts' },
    { key: 'attendance', label: 'Attendance' },
    { key: 'leave', label: 'Leave requests' },
  ],
  payroll: [
    { key: 'runs', label: 'Payroll runs' },
    { key: 'components', label: 'Components' },
    { key: 'commissions', label: 'Commissions' },
  ],
  website: [
    { key: 'pages', label: 'Pages' },
    { key: 'redirects', label: 'Redirects' },
  ],
  growth: [
    { key: 'loyalty', label: 'Loyalty', requiredPermissions: ['loyalty.manage'] },
    { key: 'reviews', label: 'Reviews', requiredPermissions: ['website.reviews.manage'] },
    { key: 'collections', label: 'Collections', requiredPermissions: ['website.merchandising.manage'] },
    { key: 'promotions', label: 'Promotions', requiredPermissions: ['website.marketing.manage'] },
    { key: 'social', label: 'Social links', requiredPermissions: ['website.social.manage'] },
  ],
  marketing: [
    { key: 'integrations', label: 'Platforms', requiredPermissions: ['marketing.integrations.view'] },
    { key: 'campaigns', label: 'Campaigns', requiredPermissions: ['marketing.campaigns.view'] },
    { key: 'social', label: 'Social studio', requiredPermissions: ['marketing.social.view'] },
    { key: 'seo', label: 'SEO audit', requiredPermissions: ['marketing.seo.view'] },
  ],
}

function visibleStaffSections(page: PageKey, permissions: string[]): StaffSection[] {
  return (staffSections[page] ?? []).filter((section) =>
    !section.requiredPermissions?.length || section.requiredPermissions.every((permission) => permissions.includes(permission)))
}

function resolveStaffSection(page: PageKey, section: string, permissions: string[]): string {
  const sections = visibleStaffSections(page, permissions)
  if (!sections.length) return ''
  return sections.some((candidate) => candidate.key === section) ? section : sections[0].key
}

function parseStaffRoute(hash: string): { page: PageKey; section: string } | null {
  if (hash !== '#staff' && !hash.startsWith('#staff/')) return null
  const [pageKey, section = ''] = hash.replace(/^#staff\/?/, '').split('/')
  if (!navigation.some((item) => item.key === pageKey)) return null
  return { page: pageKey as PageKey, section }
}

type NavigationItem = PermissionAwareNavigationItem & {
  key: PageKey
  icon: string
}

const navigation: NavigationItem[] = [
  { key: 'overview', label: 'Overview', href: '#overview', icon: '⌂', requiredPermissions: ['organization.view', 'branch.view'], requiresAllPermissions: true },
  { key: 'inbox', label: 'Mailbox', href: '#inbox', icon: '✉' },
  { key: 'analytics', label: 'Analytics & reports', href: '#analytics', icon: '▥', requiredPermissions: ['analytics.view'] },
  { key: 'branches', label: 'Branches', href: '#branches', icon: '⌖', requiredPermissions: ['branch.view', 'organization.view'], requiresAllPermissions: true },
  { key: 'departments', label: 'Departments', href: '#departments', icon: '▦', requiredPermissions: ['department.view'] },
  { key: 'users', label: 'Staff', href: '#users', icon: '♙', requiredPermissions: ['users.view'] },
  { key: 'roles', label: 'Roles & access', href: '#roles', icon: '◇', requiredPermissions: ['roles.assign'] },
  { key: 'audit', label: 'Audit activity', href: '#audit', icon: '◷', requiredPermissions: ['audit.view'] },
  { key: 'customers', label: 'Customers', href: '#customers', icon: '♡', requiredPermissions: ['customers.view'] },
  { key: 'services', label: 'Services', href: '#services', icon: '✳', requiredPermissions: ['services.view'] },
  { key: 'appointments', label: 'Appointments', href: '#appointments', icon: '◷', requiredPermissions: ['appointments.view'] },
  { key: 'pos', label: 'Point of sale', href: '#pos', icon: '▣', requiredPermissions: ['pos.view'] },
  { key: 'pos-management', label: 'POS management', href: '#pos-management', icon: '▦', requiredPermissions: ['pos.shift.view_all', 'pos.sales.view_all', 'pos.pin.manage', 'pos.register.manage', 'pos.settings.manage'] },
  { key: 'clinical', label: 'Clinical care', href: '#clinical', icon: '✚', requiredPermissions: ['clinical.view'] },
  { key: 'hr', label: 'People & HR', href: '#hr', icon: '♙', requiredPermissions: ['hr.view'] },
  { key: 'payroll', label: 'Payroll', href: '#payroll', icon: '¤', requiredPermissions: ['payroll.view', 'payroll.salary.view'], requiresAllPermissions: true },
  { key: 'inventory', label: 'Inventory', href: '#inventory', icon: '▤', requiredPermissions: ['inventory.view'] },
  { key: 'procurement', label: 'Procurement', href: '#procurement', icon: '⇄', requiredPermissions: ['procurement.view'] },
  { key: 'ecommerce', label: 'Online orders', href: '#ecommerce', icon: '▣', requiredPermissions: ['ecommerce.orders.view'] },
  { key: 'payments', label: 'Payments & reconciliation', href: '#payments', icon: '¤', requiredPermissions: ['payments.reconcile'] },
  { key: 'accounting', label: 'Accounting', href: '#accounting', icon: '▥', requiredPermissions: ['accounting.view'] },
  { key: 'crm', label: 'CRM & marketing', href: '#crm', icon: '◎', requiredPermissions: ['crm.view'] },
  { key: 'website', label: 'Website content', href: '#website', icon: '⌘', requiredPermissions: ['website.content.view'] },
  { key: 'growth', label: 'Website growth', href: '#growth', icon: '✳', requiredPermissions: ['loyalty.manage', 'website.reviews.manage', 'website.merchandising.manage', 'website.marketing.manage', 'website.social.manage'] },
  { key: 'marketing', label: 'Marketing centre', href: '#marketing', icon: '◎', requiredPermissions: ['marketing.integrations.view', 'marketing.campaigns.view', 'marketing.social.view', 'marketing.seo.view'] },
  { key: 'organization', label: 'Clinic profile', href: '#organization', icon: '⚑', requiredPermissions: ['organization.view'] },
  { key: 'email', label: 'Email settings', href: '#email', icon: '✉', requiredPermissions: ['settings.manage'] },
  { key: 'notifications', label: 'Notification rules', href: '#notifications', icon: '◈', requiredPermissions: ['settings.manage'] },
  { key: 'account', label: 'Account & security', href: '#account', icon: '⚙' },
]

const navigationGroups: { key: string; label: string; pages: PageKey[] }[] = [
  { key: 'workspace', label: 'Workspace', pages: ['overview', 'inbox', 'analytics'] },
  { key: 'organization', label: 'Organization', pages: ['branches', 'departments', 'users', 'roles', 'audit'] },
  { key: 'care', label: 'Care & customers', pages: ['customers', 'services', 'appointments', 'clinical'] },
  { key: 'operations', label: 'Operations', pages: ['hr', 'inventory', 'procurement', 'ecommerce'] },
  { key: 'pos', label: 'Point of sale', pages: ['pos'] },
  { key: 'pos-management', label: 'POS management', pages: ['pos-management'] },
  { key: 'finance', label: 'Finance', pages: ['payroll', 'payments', 'accounting'] },
  { key: 'growth', label: 'Growth & marketing', pages: ['crm', 'website', 'growth', 'marketing'] },
  { key: 'settings', label: 'Settings', pages: ['organization', 'email', 'notifications', 'account'] },
]

const roleDescriptions: Record<string, string> = {
  'Super Admin': 'Platform administration',
  'Managing Director': 'Strategic leadership & approvals',
  'Clinic Administrator': 'Clinic operations',
  'Clinical Dermatologist': 'Dermatology & treatment plans',
  'Medical Aesthetician / Skincare Therapist': 'Aesthetic treatments',
  'Receptionist & Customer Service Officer': 'Front desk & customer care',
  'Sales & Inventory Officer': 'Sales, stock & fulfilment',
  Accountant: 'Finance & payroll',
  'Marketing Officer': 'Marketing & growth',
  Nurse: 'Nursing & clinical support',
  'Customer / Patient': 'Patient account',
}

const roleLandingPages: Record<string, PageKey> = {
  'Managing Director': 'overview',
  'Clinic Administrator': 'overview',
  'Clinical Dermatologist': 'customers',
  'Medical Aesthetician / Skincare Therapist': 'appointments',
  'Receptionist & Customer Service Officer': 'appointments',
  'Sales & Inventory Officer': 'pos',
  Accountant: 'overview',
  'Marketing Officer': 'crm',
  Nurse: 'clinical',
}

const TODAY_LABEL = new Intl.DateTimeFormat('en', {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
}).format(new Date()).toUpperCase()
const BOOKING_DATE_MIN = new Date().toISOString().slice(0, 10)
const BOOKING_DATE_MAX = new Date(Date.now() + 180 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

function SkincarePhotoCarousel({ variant }: { variant: 'hero' | 'login' }) {
  const [activeSlide, setActiveSlide] = useState(0)
  const [rotationEnabled, setRotationEnabled] = useState(true)

  useEffect(() => {
    if (!rotationEnabled) return
    const timer = window.setInterval(() => {
      setActiveSlide((current) => (current + 1) % skincarePhotoSlides.length)
    }, 6500)
    return () => window.clearInterval(timer)
  }, [rotationEnabled])

  function showSlide(index: number) {
    setActiveSlide((index + skincarePhotoSlides.length) % skincarePhotoSlides.length)
  }

  return (
    <div
      className={`skincare-photo-carousel skincare-photo-carousel-${variant}`}
      role="region"
      aria-label="Oshel skincare photography"
      aria-roledescription="carousel"
    >
      <div className="skincare-photo-slides" aria-live="off">
        {skincarePhotoSlides.map((slide, index) => (
          <div
            className={`skincare-photo-slide${activeSlide === index ? ' skincare-photo-slide-active' : ''}`}
            key={slide.src}
            role="group"
            aria-roledescription="slide"
            aria-label={`${index + 1} of ${skincarePhotoSlides.length}`}
            aria-hidden={activeSlide !== index}
          >
            <img src={slide.src} alt={activeSlide === index ? slide.alt : ''} />
            <span className="skincare-photo-caption">{slide.caption}</span>
          </div>
        ))}
      </div>
      <div className="skincare-photo-controls">
        <button type="button" aria-label="Previous skincare photo" onClick={() => showSlide(activeSlide - 1)}>‹</button>
        <div className="skincare-photo-dots" aria-label="Choose a skincare photo">
          {skincarePhotoSlides.map((slide, index) => (
            <button
              type="button"
              key={slide.src}
              aria-label={`Show photo ${index + 1}`}
              aria-pressed={activeSlide === index}
              onClick={() => showSlide(index)}
            />
          ))}
        </div>
        <button type="button" aria-label={rotationEnabled ? 'Pause photo rotation' : 'Resume photo rotation'} onClick={() => setRotationEnabled((enabled) => !enabled)}>{rotationEnabled ? 'Ⅱ' : '▶'}</button>
        <button type="button" aria-label="Next skincare photo" onClick={() => showSlide(activeSlide + 1)}>›</button>
      </div>
    </div>
  )
}

function LoginScreen({
  onLogin,
  initialNotice,
  onOpenStore,
}: {
  onLogin: (token: string, user: AuthUser) => void
  initialNotice: string
  onOpenStore: () => void
}) {
  const [resetMode, setResetMode] = useState(false)
  const [notice, setNotice] = useState(initialNotice)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [resetComplete, setResetComplete] = useState(false)
  const [email, setEmail] = useState(new URLSearchParams(window.location.search).get('email') ?? '')
  const resetToken = window.location.pathname.match(/\/reset-password\/([^/]+)/)?.[1]
  const isResetFlow = Boolean(resetToken && !resetComplete)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setNotice('')
    setBusy(true)
    const form = new FormData(event.currentTarget)

    try {
      if (isResetFlow && resetToken) {
        await apiRequest<{ message: string }>('/auth/password/reset', {
          method: 'POST',
          body: JSON.stringify({
            token: decodeURIComponent(resetToken),
            email,
            password: String(form.get('password')),
            password_confirmation: String(form.get('password_confirmation')),
          }),
        })
        window.history.replaceState({}, '', '/')
        setResetComplete(true)
        setNotice('Your password has been updated. Sign in with the new password.')
      } else if (resetMode) {
        const result = await apiRequest<{ message: string }>('/auth/password/forgot', {
          method: 'POST',
          body: JSON.stringify({ email }),
        })
        setNotice(result.message)
      } else {
        const result = await apiRequest<{ access_token: string; user: AuthUser }>('/auth/login', {
          method: 'POST',
          body: JSON.stringify({
            email,
            password: String(form.get('password')),
            device_name: 'Oshel web',
          }),
        })
        onLogin(result.access_token, result.user)
      }
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const heading = isResetFlow
    ? 'Choose a new password'
    : resetMode
      ? 'Reset your password'
      : 'Welcome back'

  return (
    <main className="auth-page">
      <section className="auth-art" aria-label="Oshel clinic">
        <SkincarePhotoCarousel variant="login" />
        <div className="brand brand-light">
          <span className="brand-mark" aria-hidden="true">o</span>
          <span>oshel<span className="brand-period">.</span></span>
        </div>
        <div className="art-copy">
          <span className="eyebrow">CLINIC OPERATIONS</span>
          <h1>Care that feels<br />considered.</h1>
          <p>One calm place to support your team and the people you care for.</p>
        </div>
        <span className="art-caption">A little more care, in every detail.</span>
      </section>

      <section className="auth-panel">
        <div className="auth-form-wrap">
          <div className="brand brand-dark">
            <span className="brand-mark" aria-hidden="true">o</span>
            <span>oshel<span className="brand-period">.</span></span>
          </div>
          <span className="eyebrow">OSHEL SKIN CARE</span>
          <h2>{heading}</h2>
          <p className="muted">
            {resetToken
              ? 'Use a strong password you have not used before.'
              : resetMode
                ? 'We’ll send a reset link if there is an account for that email.'
                : 'Sign in to continue to your clinic workspace.'}
          </p>

          {notice && <div className="notice success" role="status">{notice}</div>}
          {error && <div className="notice error" role="alert">{error}</div>}

          <form onSubmit={submit} className="form-stack">
            <label className="field">
              <span>Email address</span>
              <input
                type="email"
                name="email"
                autoComplete="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
              />
            </label>
            {isResetFlow ? (
              <>
                <PasswordField name="password" label="New password" autoComplete="new-password" />
                <PasswordField name="password_confirmation" label="Confirm new password" autoComplete="new-password" />
              </>
            ) : !resetMode ? (
              <PasswordField name="password" label="Password" autoComplete="current-password" />
            ) : null}
            <button className="button button-primary button-wide" disabled={busy}>
              {busy ? 'Please wait…' : isResetFlow ? 'Update password' : resetMode ? 'Send reset link' : 'Sign in'}
            </button>
          </form>

          {!isResetFlow && (
            <button
              className="text-button auth-switch"
              type="button"
              onClick={() => {
                setResetMode(!resetMode)
                setError('')
                setNotice('')
              }}
            >
              {resetMode ? 'Return to sign in' : 'Forgot your password?'}
            </button>
          )}
          <div className="auth-footnote"><span>Secure access for your clinic team.</span><button className="text-button" type="button" onClick={onOpenStore}>Return to the public website</button></div>
        </div>
      </section>
    </main>
  )
}

function PublicEnquiryForm({ organizationSlug, branchId }: { organizationSlug: string; branchId: string }) {
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const query = new URLSearchParams(window.location.search)
    if (!String(form.get('email') ?? '').trim() && !String(form.get('phone') ?? '').trim()) {
      setError('Provide an email address or phone number so the team can respond.')
      return
    }
    setBusy(true)
    setMessage('')
    setError('')
    try {
      const response = await apiRequest<{ message: string }>('/public/enquiries', {
        method: 'POST',
        body: JSON.stringify({
          organization_slug: organizationSlug,
          branch_id: branchId ? Number(branchId) : null,
          name: String(form.get('name') ?? ''),
          email: String(form.get('email') ?? '') || null,
          phone: String(form.get('phone') ?? '') || null,
          subject: String(form.get('subject') ?? ''),
          message: String(form.get('message') ?? '') || null,
          contact_consent: form.get('contact_consent') === 'on',
          utm_source: query.get('utm_source'),
          utm_medium: query.get('utm_medium'),
          utm_campaign: query.get('utm_campaign'),
          utm_content: query.get('utm_content'),
          utm_term: query.get('utm_term'),
          referrer: document.referrer ? new URL(document.referrer).origin : null,
          landing_page: window.location.pathname.slice(0, 500),
        }),
      })
      setMessage(response.message)
      formElement.reset()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="storefront-enquiry-panel">
      <span className="eyebrow">SEND AN ENQUIRY</span>
      <h3>How can we help?</h3>
      <p className="muted">Share your contact details and our team will follow up about your request. Please do not include medical or clinical details. This is not a marketing subscription.</p>
      {message && <div className="notice success" role="status">{message}</div>}
      {error && <div className="notice error" role="alert">{error}</div>}
      <form className="storefront-checkout-form" onSubmit={(event) => void submit(event)}>
        <label>Your name<input name="name" required maxLength={180} autoComplete="name" /></label>
        <label>Email address<input name="email" type="email" maxLength={255} autoComplete="email" /></label>
        <label>Phone number<input name="phone" type="tel" maxLength={40} autoComplete="tel" /></label>
        <label>Enquiry topic<select name="subject" required defaultValue="Booking enquiry"><option>Booking enquiry</option><option>Product question</option><option>Pricing and availability</option><option>Partnership enquiry</option><option>Other</option></select></label>
        <label className="storefront-wide-field">Message (non-clinical)<textarea name="message" maxLength={2000} /></label>
        <label className="storefront-consent-check"><input name="contact_consent" type="checkbox" required /> I agree to be contacted about this enquiry.</label>
        <button className="button button-primary" type="submit" disabled={busy || !organizationSlug}>{busy ? 'Sending…' : 'Send enquiry'}</button>
      </form>
    </div>
  )
}

function StorefrontPage({
  user,
  onLogin,
  onStaffLogin,
  onExit,
}: {
  user: AuthUser | null
  onLogin: (token: string, user: AuthUser) => void
  onStaffLogin: () => void
  onExit: () => void
}) {
  const userId = user?.id
  const [initialRoute] = useState<PublicRoute>(() => readPublicRoute())
  const [publicPage, setPublicPage] = useState<PublicPageKey>(initialRoute.page)
  const [customPageSlug, setCustomPageSlug] = useState<string | null>(initialRoute.customPageSlug)
  const [journalArticleSlug, setJournalArticleSlug] = useState<string | null>(initialRoute.journalArticleSlug)
  const [organizations, setOrganizations] = useState<{ name: string; slug: string }[]>([])
  const [organizationSlug, setOrganizationSlug] = useState(initialRoute.organizationSlug)
  const [organizationName, setOrganizationName] = useState('')
  const [organizationCurrency, setOrganizationCurrency] = useState('KES')
  const [branches, setBranches] = useState<StorefrontResponse['branches']>([])
  const [branchId, setBranchId] = useState('')
  const [products, setProducts] = useState<StoreProduct[]>([])
  const [services, setServices] = useState<ServiceRecord[]>([])
  const [servicesLoading, setServicesLoading] = useState(false)
  const [servicesError, setServicesError] = useState('')
  const [homepage, setHomepage] = useState<Pick<WebsitePage, 'title' | 'eyebrow' | 'summary' | 'body' | 'seo_title' | 'seo_description'> | null>(null)
  const [pageContent, setPageContent] = useState<Pick<WebsitePage, 'slug' | 'page_type' | 'title' | 'eyebrow' | 'summary' | 'body' | 'seo_title' | 'seo_description' | 'cover_image_url' | 'published_at'> | null>(null)
  const [contentError, setContentError] = useState('')
  const [productSections, setProductSections] = useState<{ slug: string; title: string; description: string | null; products: Pick<StoreProduct, 'id' | 'slug' | 'name' | 'description' | 'selling_price' | 'primary_image_url'>[] }[]>([])
  const [publicPromotions, setPublicPromotions] = useState<{ title: string; message: string | null; coupon_code: string | null }[]>([])
  const [publicReviews, setPublicReviews] = useState<{ id: number; rating: number; display_name: string; review_text: string; featured: boolean; product_name: string | null; service_name: string | null }[]>([])
  const [journalArticles, setJournalArticles] = useState<{ slug: string; title: string; eyebrow: string | null; summary: string | null; body: string | null; seo_title: string | null; seo_description: string | null; cover_image_url: string | null; published_at: string }[]>([])
  const [socialLinks, setSocialLinks] = useState<{ platform: string; url: string }[]>([])
  const [newsletterMessage, setNewsletterMessage] = useState('')
  const [privacyBannerOpen, setPrivacyBannerOpen] = useState(() => Boolean(initialRoute.organizationSlug && !hasStoredPrivacyDecision(initialRoute.organizationSlug)))
  const [analyticsAllowed, setAnalyticsAllowed] = useState(() => storedAnalyticsConsent(initialRoute.organizationSlug))
  const lastTrackedPage = useRef('')
  const redirectVisits = useRef(new Set<string>())
  const [cart, setCart] = useState<{ product_id: number; quantity: number }[]>(() => {
    try {
      const saved = window.localStorage.getItem('oshel.storefront.cart.v1')
      return saved ? JSON.parse(saved) as { product_id: number; quantity: number }[] : []
    } catch {
      return []
    }
  })
  const [fulfillment, setFulfillment] = useState<'collection' | 'delivery'>('collection')
  const [couponCode, setCouponCode] = useState('')
  const [accountMode, setAccountMode] = useState<'sign-in' | 'register' | 'verify'>('sign-in')
  const [verificationEmail, setVerificationEmail] = useState('')
  const [accountOpen, setAccountOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [search, setSearch] = useState('')
  const [notice, setNotice] = useState(() => new URLSearchParams(window.location.search).get('payment_return') === '1'
    ? 'Payment return received. Sign in to check the provider-confirmed order status.'
    : '')
  const [error, setError] = useState('')
  const [order, setOrder] = useState<EcommerceOrder | null>(null)
  const [paymentOptions, setPaymentOptions] = useState<CustomerPaymentOption[]>([])
  const [selectedPaymentProvider, setSelectedPaymentProvider] = useState<CustomerPaymentOption['id']>('mpesa')
  const [paymentPhone, setPaymentPhone] = useState('')
  const [paymentStatus, setPaymentStatus] = useState<CustomerPayment | null>(null)
  const paymentRequestKeys = useRef(new Map<string, string>())
  const [bookingOpen, setBookingOpen] = useState(false)
  const [bookingServiceId, setBookingServiceId] = useState<number | null>(null)
  const [detailType, setDetailType] = useState<'product' | 'service' | null>(initialRoute.detailType)
  const [detailId, setDetailId] = useState<number | null>(initialRoute.detailId)
  const [detailSlug, setDetailSlug] = useState<string | null>(initialRoute.detailSlug ?? null)
  const [remoteDetail, setRemoteDetail] = useState<{ product?: StoreProduct; service?: ServiceRecord } | null>(null)
  const [detailResolving, setDetailResolving] = useState(false)
  const [activeImage, setActiveImage] = useState<string | null>(null)
  const [productCategory, setProductCategory] = useState('')
  const [productSort, setProductSort] = useState<'featured' | 'price-asc' | 'price-desc' | 'name'>('featured')
  const [serviceCategory, setServiceCategory] = useState('')
  const [serviceSort, setServiceSort] = useState<'featured' | 'price-asc' | 'price-desc' | 'duration'>('featured')
  const [reviewRating, setReviewRating] = useState('')
  const [journalSearch, setJournalSearch] = useState('')

  useEffect(() => {
    void apiRequest<StorefrontResponse>('/public/storefront')
      .then((response) => {
        const available = response.organizations ?? []
        setOrganizations(available)
        const preferred = import.meta.env.VITE_STORE_ORGANIZATION_SLUG
        const selected = available.find((organization) => organization.slug === initialRoute.organizationSlug)
          ?? available.find((organization) => organization.slug === preferred)
          ?? (available.length === 1 ? available[0] : null)
        if (selected) {
          setOrganizationSlug(selected.slug)
          setPrivacyBannerOpen(!hasStoredPrivacyDecision(selected.slug))
          setAnalyticsAllowed(storedAnalyticsConsent(selected.slug))
        }
      })
      .catch((requestError: unknown) => setError(getErrorMessage(requestError)))
      .finally(() => setLoading(false))
  }, [initialRoute.organizationSlug])

  useEffect(() => {
    if (!organizationSlug) return
    let active = true
    void apiRequest<StorefrontResponse>(`/public/storefront?organization_slug=${encodeURIComponent(organizationSlug)}`)
      .then((response) => {
        if (!active) return
        setOrganizationName(response.organization?.name ?? '')
        setOrganizationCurrency(response.organization?.currency_code || 'KES')
        setBranches(response.branches)
        setLoading(true)
        setServices([])
        setServicesLoading(true)
        setServicesError('')
        setBranchId(String(response.branches[0]?.id ?? ''))
      })
      .catch((requestError: unknown) => {
        if (active) {
          setError(getErrorMessage(requestError))
          setServicesLoading(false)
        }
      })
    return () => { active = false }
  }, [organizationSlug])

  useEffect(() => {
    if (!organizationSlug) return
    let active = true
    const query = `organization_slug=${encodeURIComponent(organizationSlug)}`
    void Promise.all([
      apiRequest<{ sections: typeof productSections }>(`/public/product-sections?${query}`),
      apiRequest<{ promotions: typeof publicPromotions }>(`/public/promotions?${query}`),
      apiRequest<{ reviews: typeof publicReviews }>(`/public/reviews?${query}`),
      apiRequest<{ articles: typeof journalArticles }>(`/public/journal?${query}`),
      apiRequest<{ links: typeof socialLinks }>(`/public/social-links?${query}`),
    ]).then(([sections, promotions, reviews, articles, links]) => {
      if (!active) return
      setProductSections(sections.sections)
      setPublicPromotions(promotions.promotions)
      setPublicReviews(reviews.reviews)
      setJournalArticles(articles.articles)
      setSocialLinks(links.links)
    }).catch((requestError: unknown) => {
      if (active) setContentError(getErrorMessage(requestError))
    })
    return () => { active = false }
  }, [organizationSlug])

  useEffect(() => {
    if (!organizationSlug || !branchId) return
    let active = true
    void apiRequest<StorefrontResponse>(
      `/public/storefront?organization_slug=${encodeURIComponent(organizationSlug)}&branch_id=${encodeURIComponent(branchId)}`,
    )
      .then((response) => {
        if (!active) return
        setOrganizationName(response.organization?.name ?? '')
        setOrganizationCurrency(response.organization?.currency_code || 'KES')
        setBranches(response.branches)
        setProducts(response.products ?? [])
        setError('')
      })
      .catch((requestError: unknown) => {
        if (active) setError(getErrorMessage(requestError))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [organizationSlug, branchId])

  useEffect(() => {
    if (!branchId) return
    let active = true
    void apiRequest<ServiceRecord[]>(`/public/services?branch_id=${encodeURIComponent(branchId)}`)
      .then((response) => {
        if (active) setServices(response)
      })
      .catch((requestError: unknown) => {
        if (active) setServicesError(getErrorMessage(requestError))
      })
      .finally(() => {
        if (active) setServicesLoading(false)
      })
    return () => { active = false }
  }, [branchId])

  useEffect(() => {
    if (!organizationSlug) return
    let active = true
    void apiRequest<{ page: typeof homepage }>(
      `/public/site-content?organization_slug=${encodeURIComponent(organizationSlug)}&slug=home`,
    )
      .then((result) => {
        if (active) {
          setHomepage(result.page)
          setContentError('')
        }
      })
      .catch((requestError: unknown) => {
        if (active) setContentError(getErrorMessage(requestError))
      })
    return () => { active = false }
  }, [organizationSlug])

  useEffect(() => {
    const slug = customPageSlug ?? (publicPage === 'about' ? 'about' : journalArticleSlug)
    if (!organizationSlug || !slug) return
    const controller = new AbortController()
    void apiRequest<{ page: typeof pageContent }>(
      `/public/site-content?organization_slug=${encodeURIComponent(organizationSlug)}&slug=${encodeURIComponent(slug)}`,
      { signal: controller.signal },
    )
      .then(({ page }) => {
        setPageContent(page)
        if (page) return
        const sourcePath = journalArticleSlug ? `/journal/${journalArticleSlug}` : `/${slug}`
        const visitKey = `${organizationSlug}:${sourcePath}`
        if (redirectVisits.current.has(visitKey) || redirectVisits.current.size >= 5) {
          setContentError('A website redirect loop was detected. Please contact the website administrator.')
          return
        }
        redirectVisits.current.add(visitKey)
        void apiRequest<{ redirect: { target_path: string; status_code: number } | null }>(
          `/public/seo/redirect?organization_slug=${encodeURIComponent(organizationSlug)}&source_path=${encodeURIComponent(sourcePath)}`,
          { signal: controller.signal },
        ).then(({ redirect }) => {
          if (controller.signal.aborted || !redirect) return
          const nextPath = `/${encodeURIComponent(organizationSlug)}${redirect.target_path}`
          window.history.replaceState({}, '', nextPath)
          const next = readPublicRoute()
          setPublicPage(next.page)
          setCustomPageSlug(next.customPageSlug)
          setJournalArticleSlug(next.journalArticleSlug)
          setDetailType(next.detailType)
          setDetailId(next.detailId)
          setDetailSlug(next.detailSlug ?? null)
          window.scrollTo({ top: 0, behavior: 'instant' })
        }).catch((requestError: unknown) => {
          if (!controller.signal.aborted) setContentError(getErrorMessage(requestError))
        })
      })
      .catch((requestError: unknown) => {
        if (!controller.signal.aborted) {
          setPageContent(null)
          setContentError(getErrorMessage(requestError))
        }
      })
    return () => controller.abort()
  }, [organizationSlug, publicPage, customPageSlug, journalArticleSlug])

  useEffect(() => {
    window.localStorage.setItem('oshel.storefront.cart.v1', JSON.stringify(cart))
  }, [cart])

  useEffect(() => {
    if (!userId) return
    let active = true
    void apiRequest<{ currency_code: string; providers: CustomerPaymentOption[] }>('/portal/payment-options')
      .then((response) => {
        if (!active) return
        setPaymentOptions(response.providers)
        const available = response.providers.find((provider) => provider.configured)
        if (available) setSelectedPaymentProvider(available.id)
      })
      .catch((requestError: unknown) => {
        if (active) setError(getErrorMessage(requestError))
      })
    return () => { active = false }
  }, [userId])

  useEffect(() => {
    const query = new URLSearchParams(window.location.search)
    if (!userId || query.get('payment_return') !== '1') return
    window.history.replaceState({}, '', `${window.location.pathname}${window.location.hash}`)
    void apiRequest<EcommerceOrder[]>('/portal/orders')
      .then(async (orders) => {
        const requestedOrderId = Number(query.get('order_id'))
        const latestOrder = orders.find((candidate) => candidate.id === requestedOrderId) ?? orders[0]
        if (!latestOrder) {
          setError('The returned payment could not be matched to an order on this account.')
          return
        }
        setOrder(latestOrder)
        const status = await apiRequest<{ order_status: string; payment_status: string; payment: CustomerPayment | null }>(`/portal/orders/${latestOrder.id}/payment-status`)
        setOrder((current) => current?.id === latestOrder.id ? { ...current, status: status.order_status, payment_status: status.payment_status } : current)
        setPaymentStatus(status.payment)
        setNotice(status.payment?.message ?? 'Your payment status is still being confirmed. Check back shortly.')
      })
      .catch((requestError: unknown) => setError(getErrorMessage(requestError)))
  }, [userId])

  useEffect(() => {
    const orderId = order?.id
    const paymentState = paymentStatus?.status
    if (!userId || orderId === undefined || !paymentState || !['pending', 'verification_pending'].includes(paymentState)) return
    let active = true
    const timer = window.setInterval(() => {
      void apiRequest<{ order_status: string; payment_status: string; payment: CustomerPayment | null }>(`/portal/orders/${orderId}/payment-status`)
        .then((status) => {
          if (!active) return
          setOrder((current) => current?.id === orderId ? { ...current, status: status.order_status, payment_status: status.payment_status } : current)
          setPaymentStatus(status.payment)
          if (status.payment?.status === 'paid') setNotice(status.payment.message)
          else if (status.payment?.status === 'failed') setNotice(status.payment.message)
        })
        .catch((requestError: unknown) => {
          if (active) setError(getErrorMessage(requestError))
        })
    }, 5000)
    return () => {
      active = false
      window.clearInterval(timer)
    }
  }, [userId, order?.id, paymentStatus?.status])

  const filteredProducts = (branchId ? products : []).filter((product) => {
    const term = search.trim().toLowerCase()
    return !term || `${product.name} ${product.brand ?? ''} ${product.sku} ${product.category_name ?? ''}`.toLowerCase().includes(term)
  })
  const cartLines = cart.map((line) => ({
    ...line,
    product: products.find((product) => product.id === line.product_id),
  })).filter((line): line is typeof line & { product: StoreProduct } => Boolean(line.product))
  const subtotalCents = cartLines.reduce((total, line) => total + Math.round(Number(line.product.selling_price) * 100 * line.quantity), 0)
  const subtotal = (subtotalCents / 100).toFixed(2)
  const money = (amount: number | string) => `KES ${Number(amount).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  const bagCount = cart.reduce((total, line) => total + line.quantity, 0)
  const branchLabel = branches.find((branch) => String(branch.id) === branchId)?.name ?? organizationName
  const servicePrice = (service: ServiceRecord) => {
    const branchService = service.branches.find((item) => String(item.id) === branchId) ?? service.branches[0]
    return branchService ? Number(branchService.price) : null
  }
  const productCategories = Array.from(new Set(products.map((product) => product.category_name).filter((value): value is string => Boolean(value)))).sort()
  const serviceCategories = Array.from(new Set(services.map((service) => service.category?.name ?? service.classification).filter(Boolean))).sort()
  const visibleProducts = filteredProducts
    .filter((product) => !productCategory || product.category_name === productCategory)
    .slice()
    .sort((left, right) => {
      if (productSort === 'price-asc') return Number(left.selling_price) - Number(right.selling_price)
      if (productSort === 'price-desc') return Number(right.selling_price) - Number(left.selling_price)
      if (productSort === 'name') return left.name.localeCompare(right.name)
      return 0
    })
  const visibleServices = services
    .filter((service) => !serviceCategory || (service.category?.name ?? service.classification) === serviceCategory)
    .slice()
    .sort((left, right) => {
      const leftPrice = servicePrice(left) ?? 0
      const rightPrice = servicePrice(right) ?? 0
      if (serviceSort === 'price-asc') return leftPrice - rightPrice
      if (serviceSort === 'price-desc') return rightPrice - leftPrice
      if (serviceSort === 'duration') return left.duration_minutes - right.duration_minutes
      return 0
    })
  const visibleReviews = publicReviews.filter((review) => !reviewRating || String(review.rating) === reviewRating)
  const visibleJournal = journalArticles.filter((article) => {
    const term = journalSearch.trim().toLowerCase()
    return !term || `${article.title} ${article.eyebrow ?? ''} ${article.summary ?? ''} ${article.body ?? ''}`.toLowerCase().includes(term)
  })
  const productPages = usePaginatedList(visibleProducts, 8)
  const servicePages = usePaginatedList(visibleServices, 6)
  const reviewPages = usePaginatedList(visibleReviews, 6)
  const journalPages = usePaginatedList(visibleJournal, 6)
  const detailProduct = detailType === 'product'
    ? products.find((product) => (detailSlug ? product.slug === detailSlug : product.id === detailId)) ?? remoteDetail?.product ?? null
    : null
  const detailService = detailType === 'service'
    ? services.find((service) => (detailSlug ? service.slug === detailSlug : service.id === detailId)) ?? remoteDetail?.service ?? null
    : null
  const detailProductQuantity = detailProduct ? cart.find((line) => line.product_id === detailProduct.id)?.quantity ?? 0 : 0
  const detailImages = (detailProduct?.images ?? detailService?.images ?? []).map((image) => image.url)
  const detailImage = activeImage
    ?? detailProduct?.primary_image_url
    ?? detailService?.primary_image_url
    ?? detailImages[0]
    ?? null
  const detailReviews = publicReviews.filter((review) => (
    detailProduct ? review.product_name === detailProduct.name : detailService ? review.service_name === detailService.name : false
  ))
  const relatedProducts = detailProduct
    ? products.filter((product) => product.id !== detailProduct.id && product.category_name === detailProduct.category_name).slice(0, 4)
    : []
  const relatedServices = detailService
    ? services.filter((service) => service.id !== detailService.id && (service.category?.name ?? service.classification) === (detailService.category?.name ?? detailService.classification)).slice(0, 4)
    : []

  useEffect(() => {
    setActiveImage(null)
    setRemoteDetail(null)
    if (!detailType || !organizationSlug || !branchId) return
    const local = detailType === 'product'
      ? products.find((product) => (detailSlug ? product.slug === detailSlug : product.id === detailId))
      : services.find((service) => (detailSlug ? service.slug === detailSlug : service.id === detailId))
    if (local) return

    let active = true
    setDetailResolving(true)
    const path = detailType === 'product' ? 'products' : 'services'
    const identifier = detailSlug ?? String(detailId ?? '')
    void apiRequest<{ product?: StoreProduct; service?: ServiceRecord }>(
      `/public/${path}/${encodeURIComponent(identifier)}?organization_slug=${encodeURIComponent(organizationSlug)}&branch_id=${encodeURIComponent(branchId)}`,
    )
      .then((response) => {
        if (active) setRemoteDetail(response)
      })
      .catch(() => {
        if (active) setRemoteDetail({})
      })
      .finally(() => {
        if (active) setDetailResolving(false)
      })
    return () => { active = false }
  }, [detailType, detailSlug, detailId, branchId, organizationSlug, products, services])

  function requestServiceBooking(service: ServiceRecord) {
    setBookingServiceId(service.id)
    if (user?.permissions.includes('appointments.book.self')) {
      setBookingOpen(true)
    } else if (!user) {
      setAccountMode('sign-in')
      setAccountOpen(true)
      setNotice('Sign in or create a customer account to request an appointment.')
    } else {
      setNotice('Online appointment booking is not enabled for this account. Please contact the clinic.')
    }
  }

  function productCard(product: StoreProduct) {
    const quantity = cart.find((line) => line.product_id === product.id)?.quantity ?? 0
    return <article className="storefront-product-card storefront-card-link" key={product.id}>
      <a className="storefront-card-anchor" href={publicProductPath(organizationSlug, product.id, product.slug)} aria-label={`View ${product.name}`}>
        {product.primary_image_url ? <img className="storefront-product-image" src={product.primary_image_url} alt={product.name} loading="lazy" /> : <span className="storefront-product-mark" aria-hidden="true">o</span>}
      </a>
      <span className="eyebrow">{product.category_name ?? 'OSHEL CARE'}</span>
      <h3><a href={publicProductPath(organizationSlug, product.id, product.slug)}>{product.name}</a></h3>
      {product.brand && <p className="muted">{product.brand}</p>}
      <p className="storefront-description">{product.description || 'A considered addition to your care routine.'}</p>
      <div className="storefront-product-footer"><strong>{money(product.selling_price)}</strong><small>{Number(product.available_quantity)} available</small></div>
      {quantity ? <div className="storefront-quantity"><button type="button" aria-label={`Remove one ${product.name}`} onClick={() => setQuantity(product.id, quantity - 1)}>−</button><span>{quantity}</span><button type="button" aria-label={`Add one ${product.name}`} disabled={quantity >= Number(product.available_quantity)} onClick={() => addProduct(product.id)}>+</button></div>
        : <button className="button button-secondary button-wide" type="button" disabled={Number(product.available_quantity) < 1} onClick={() => addProduct(product.id)}>{Number(product.available_quantity) < 1 ? 'Out of stock' : 'Add to bag'}</button>}
      <a className="text-button storefront-card-detail-link" href={publicProductPath(organizationSlug, product.id, product.slug)}>View details <span aria-hidden="true">→</span></a>
    </article>
  }

  function serviceCard(service: ServiceRecord) {
    const price = servicePrice(service)
    const branchService = service.branches.find((item) => String(item.id) === branchId) ?? service.branches[0]
    return <article className="storefront-service-card storefront-card-link" key={service.id}>
      <a className="storefront-card-anchor" href={publicServicePath(organizationSlug, service.id, service.slug)} aria-label={`View ${service.name}`}>
        {service.primary_image_url ? <img className="storefront-service-image" src={service.primary_image_url} alt={service.name} loading="lazy" /> : <span className="storefront-product-mark" aria-hidden="true">o</span>}
      </a>
      <span className="eyebrow">{service.category?.name ?? service.classification.replaceAll('_', ' ')}</span>
      <h3><a href={publicServicePath(organizationSlug, service.id, service.slug)}>{service.name}</a></h3>
      <p>{service.description || 'Speak with the clinic team to learn more about this service.'}</p>
      <div className="storefront-service-meta"><span>{service.duration_minutes} min</span>{price !== null && <strong>{branchService?.currency_code ?? organizationCurrency} {price.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong>}</div>
      <div className="storefront-card-actions">
        <a className="text-button" href={publicServicePath(organizationSlug, service.id, service.slug)}>View details <span aria-hidden="true">→</span></a>
        <button className="button button-secondary" type="button" onClick={() => requestServiceBooking(service)}>{user?.permissions.includes('appointments.book.self') ? 'Book this service' : user ? 'Contact clinic to book' : 'Sign in to book'}</button>
      </div>
    </article>
  }

  function productFilters() {
    return <div className="storefront-filter-bar">
      <div className="storefront-filter-chips" role="group" aria-label="Filter products by category">
        <button type="button" className={productCategory === '' ? 'is-active' : ''} onClick={() => setProductCategory('')} aria-pressed={productCategory === ''}>All</button>
        {productCategories.map((category) => <button type="button" key={category} className={productCategory === category ? 'is-active' : ''} onClick={() => setProductCategory(category)} aria-pressed={productCategory === category}>{category}</button>)}
      </div>
      <label className="storefront-sort">Sort<select value={productSort} onChange={(event) => setProductSort(event.target.value as typeof productSort)}><option value="featured">Featured</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option><option value="name">Name A–Z</option></select></label>
    </div>
  }

  function serviceFilters() {
    return <div className="storefront-filter-bar">
      <div className="storefront-filter-chips" role="group" aria-label="Filter services by category">
        <button type="button" className={serviceCategory === '' ? 'is-active' : ''} onClick={() => setServiceCategory('')} aria-pressed={serviceCategory === ''}>All</button>
        {serviceCategories.map((category) => <button type="button" key={category} className={serviceCategory === category ? 'is-active' : ''} onClick={() => setServiceCategory(category)} aria-pressed={serviceCategory === category}>{category}</button>)}
      </div>
      <label className="storefront-sort">Sort<select value={serviceSort} onChange={(event) => setServiceSort(event.target.value as typeof serviceSort)}><option value="featured">Featured</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option><option value="duration">Shortest visit</option></select></label>
    </div>
  }

  useEffect(() => {
    const syncPage = () => {
      const next = readPublicRoute()
      redirectVisits.current.clear()
      setPublicPage(next.page)
      setCustomPageSlug(next.customPageSlug)
      setJournalArticleSlug(next.journalArticleSlug)
      setDetailType(next.detailType)
      setDetailId(next.detailId)
      setDetailSlug(next.detailSlug ?? null)
      setPageContent(null)
      if (next.organizationSlug && organizations.some((item) => item.slug === next.organizationSlug)) {
        setOrganizationSlug(next.organizationSlug)
        setPrivacyBannerOpen(!hasStoredPrivacyDecision(next.organizationSlug))
        setAnalyticsAllowed(storedAnalyticsConsent(next.organizationSlug))
      }
      window.scrollTo({ top: 0, behavior: 'instant' })
    }
    window.addEventListener('popstate', syncPage)
    window.addEventListener('hashchange', syncPage)
    return () => {
      window.removeEventListener('popstate', syncPage)
      window.removeEventListener('hashchange', syncPage)
    }
  }, [organizations])

  const defaultTitles: Record<PublicPageKey, string> = {
      home: 'Oshel Skin Care | Products, services and appointments',
      about: 'About Oshel | Thoughtful skincare and care',
      services: 'Services | Oshel Skin Care',
      shop: 'Shop | Oshel Skin Care',
      rewards: 'Oshel Rewards | Customer loyalty',
      testimonials: 'Customer stories | Oshel Skin Care',
      journal: 'Skincare journal | Oshel Skin Care',
      contact: 'Contact & locations | Oshel Skin Care',
      custom: pageContent?.title ? `${pageContent.title} | Oshel Skin Care` : 'Oshel Skin Care',
  }
  const currentPageContent = publicPage === 'home' ? homepage : pageContent
  const detailSeoTitle = detailProduct
    ? `${detailProduct.name} | ${organizationName || 'Oshel Skin Care'}`
    : detailService
      ? `${detailService.name} | ${organizationName || 'Oshel Skin Care'}`
      : ''
  const detailSeoDescription = detailProduct?.description || detailService?.description || ''
  const pageTitle = detailSeoTitle
    || currentPageContent?.seo_title
    || (publicPage === 'home' && !homepage ? defaultTitles.home : currentPageContent?.title)
    || defaultTitles[publicPage]
  const pageDescription = detailSeoDescription
    || currentPageContent?.seo_description
    || currentPageContent?.summary
    || 'Discover thoughtfully selected skincare products and in-clinic services at Oshel Skin Care.'
  const canonicalPath = detailType === 'product' && (detailId || detailSlug)
    ? publicProductPath(organizationSlug, detailId ?? detailProduct?.id ?? 0, detailSlug)
    : detailType === 'service' && (detailId || detailSlug)
      ? publicServicePath(organizationSlug, detailId ?? detailService?.id ?? 0, detailSlug)
      : publicPathFor(
        organizationSlug,
        publicPage,
        customPageSlug ?? journalArticleSlug,
      )
  const publicSiteUrl = (import.meta.env.VITE_PUBLIC_SITE_URL || window.location.origin).replace(/\/+$/, '')
  const canonicalUrl = `${publicSiteUrl}${canonicalPath}`

  useEffect(() => {
    document.title = pageTitle
    const tags: { selector: string; key: string; value: string }[] = [
      { selector: 'meta[name="description"]', key: 'name', value: pageDescription },
      { selector: 'meta[name="robots"]', key: 'name', value: 'index,follow' },
      { selector: 'meta[property="og:title"]', key: 'property', value: pageTitle },
      { selector: 'meta[property="og:description"]', key: 'property', value: pageDescription },
      { selector: 'meta[property="og:type"]', key: 'property', value: journalArticleSlug ? 'article' : 'website' },
      { selector: 'meta[property="og:url"]', key: 'property', value: canonicalUrl },
      { selector: 'meta[property="og:image"]', key: 'property', value: pageContent?.cover_image_url
        ? new URL(pageContent.cover_image_url, publicSiteUrl).toString()
        : new URL('/images/public-site/hero-clay-mask.jpg', publicSiteUrl).toString() },
      { selector: 'meta[name="twitter:card"]', key: 'name', value: 'summary_large_image' },
      { selector: 'meta[name="twitter:title"]', key: 'name', value: pageTitle },
      { selector: 'meta[name="twitter:description"]', key: 'name', value: pageDescription },
    ]
    const previous = tags.map(({ selector, key, value }) => {
      let tag = document.querySelector<HTMLMetaElement>(selector)
      const created = !tag
      if (!tag) {
        tag = document.createElement('meta')
        tag.setAttribute(key === 'property' ? 'property' : 'name', selector.includes('og:') ? selector.match(/og:[^"]+/)?.[0] ?? '' : selector.match(/\[name="([^"]+)/)?.[1] ?? '')
        document.head.append(tag)
      }
      const oldValue = tag.content
      tag.content = value
      return { tag, created, oldValue }
    })
    let canonical = document.querySelector<HTMLLinkElement>('link[rel="canonical"]')
    const createdCanonical = !canonical
    if (!canonical) {
      canonical = document.createElement('link')
      canonical.rel = 'canonical'
      document.head.append(canonical)
    }
    const previousCanonical = canonical.href
    canonical.href = canonicalUrl

    const structuredData = [
      {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: organizationName || 'Oshel Skin Care',
        url: canonicalUrl,
        sameAs: socialLinks.map((link) => link.url),
      },
      {
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: pageTitle,
        description: pageDescription,
        url: canonicalUrl,
      },
      ...(detailProduct ? [{
        '@context': 'https://schema.org',
        '@type': 'Product',
        name: detailProduct.name,
        description: detailProduct.description || undefined,
        sku: detailProduct.sku,
        category: detailProduct.category_name || undefined,
        brand: detailProduct.brand ? { '@type': 'Brand', name: detailProduct.brand } : undefined,
        image: detailImage ? new URL(detailImage, publicSiteUrl).toString() : undefined,
        url: canonicalUrl,
        offers: {
          '@type': 'Offer',
          priceCurrency: organizationCurrency,
          price: Number(detailProduct.selling_price).toFixed(2),
          availability: Number(detailProduct.available_quantity) > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
          seller: { '@type': 'Organization', name: organizationName || 'Oshel Skin Care' },
        },
        ...(detailReviews.length ? {
          aggregateRating: {
            '@type': 'AggregateRating',
            ratingValue: (detailReviews.reduce((total, review) => total + review.rating, 0) / detailReviews.length).toFixed(1),
            reviewCount: detailReviews.length,
          },
        } : {}),
      }] : []),
      ...(detailService ? [{
        '@context': 'https://schema.org',
        '@type': 'Service',
        name: detailService.name,
        description: detailService.description || undefined,
        serviceType: detailService.category?.name || detailService.classification.replaceAll('_', ' '),
        provider: { '@type': 'Organization', name: organizationName || 'Oshel Skin Care' },
        image: detailImage ? new URL(detailImage, publicSiteUrl).toString() : undefined,
        url: canonicalUrl,
        offers: detailService.branches.map((branch) => ({
          '@type': 'Offer',
          name: branch.name,
          priceCurrency: branch.currency_code,
          price: Number(branch.price).toFixed(2),
        })),
      }] : []),
      ...(pageContent?.page_type === 'journal' && journalArticleSlug ? [{
        '@context': 'https://schema.org',
        '@type': 'BlogPosting',
        headline: pageContent.title,
        description: pageContent.summary || pageDescription,
        image: pageContent.cover_image_url ? new URL(pageContent.cover_image_url, publicSiteUrl).toString() : undefined,
        datePublished: pageContent.published_at || undefined,
        mainEntityOfPage: canonicalUrl,
        publisher: { '@type': 'Organization', name: organizationName || 'Oshel Skin Care' },
      }] : []),
      ...(publicPage === 'shop' && products.length ? [{
        '@context': 'https://schema.org',
        '@type': 'ItemList',
        itemListElement: products.filter((product) => Number(product.available_quantity) > 0).map((product, index) => ({
          '@type': 'ListItem',
          position: index + 1,
          item: {
            '@type': 'Product',
            name: product.name,
            description: product.description || undefined,
            sku: product.sku,
            brand: product.brand ? { '@type': 'Brand', name: product.brand } : undefined,
            offers: {
              '@type': 'Offer',
              priceCurrency: organizationCurrency,
              price: Number(product.selling_price).toFixed(2),
              availability: 'https://schema.org/InStock',
            },
          },
        })),
      }] : []),
      ...(publicPage === 'services' && services.length ? [{
        '@context': 'https://schema.org',
        '@type': 'ItemList',
        itemListElement: services.map((service, index) => {
          const branchService = service.branches.find((item) => String(item.id) === branchId) ?? service.branches[0]
          return {
            '@type': 'ListItem',
            position: index + 1,
            item: {
              '@type': 'Service',
              name: service.name,
              description: service.description || undefined,
              provider: { '@type': 'Organization', name: organizationName || 'Oshel Skin Care' },
              offers: branchService ? {
                '@type': 'Offer',
                priceCurrency: branchService.currency_code,
                price: Number(branchService.price).toFixed(2),
              } : undefined,
            },
          }
        }),
      }] : []),
      ...(publicPage === 'testimonials' && publicReviews.length ? [{
        '@context': 'https://schema.org',
        '@type': 'ItemList',
        itemListElement: publicReviews.map((review, index) => ({
          '@type': 'ListItem',
          position: index + 1,
          item: {
            '@type': 'Review',
            reviewBody: review.review_text,
            reviewRating: { '@type': 'Rating', ratingValue: review.rating, bestRating: 5 },
            author: { '@type': 'Person', name: review.display_name },
            itemReviewed: { '@type': 'Organization', name: organizationName || 'Oshel Skin Care' },
          },
        })),
      }] : []),
    ]
    let jsonLd = document.querySelector<HTMLScriptElement>('#oshel-public-structured-data')
    const createdJsonLd = !jsonLd
    if (!jsonLd) {
      jsonLd = document.createElement('script')
      jsonLd.id = 'oshel-public-structured-data'
      jsonLd.type = 'application/ld+json'
      document.head.append(jsonLd)
    }
    const previousJsonLd = jsonLd.textContent
    jsonLd.textContent = JSON.stringify(structuredData).replace(/</g, '\\u003c')

    return () => {
      previous.forEach(({ tag, created, oldValue }) => {
        if (created) tag.remove()
        else tag.content = oldValue
      })
      if (createdCanonical) canonical?.remove()
      else if (canonical) canonical.href = previousCanonical
      if (createdJsonLd) jsonLd?.remove()
      else if (jsonLd) jsonLd.textContent = previousJsonLd
    }
  }, [pageTitle, pageDescription, publicSiteUrl, canonicalUrl, pageContent, journalArticleSlug, organizationName, organizationCurrency, socialLinks, publicPage, products, services, publicReviews, branchId, detailProduct, detailService, detailImage, detailReviews])

  useEffect(() => {
    if (!organizationSlug || !analyticsAllowed) return
    const anonymousId = window.localStorage.getItem(`oshel.privacy-id.${organizationSlug}`)
    if (!anonymousId) return
    const trackingKey = `${organizationSlug}:${canonicalPath}`
    if (lastTrackedPage.current === trackingKey) return
    lastTrackedPage.current = trackingKey
    void apiRequest('/public/analytics/events', {
      method: 'POST',
      body: JSON.stringify({
        organization_slug: organizationSlug,
        anonymous_id: anonymousId,
        event_id: crypto.randomUUID(),
        event_name: 'page_view',
        page_path: canonicalPath,
      }),
    }).catch((requestError: unknown) => setError(getErrorMessage(requestError)))
  }, [organizationSlug, analyticsAllowed, canonicalPath])

  function setQuantity(productId: number, quantity: number) {
    setCart((current) => quantity < 1
      ? current.filter((line) => line.product_id !== productId)
      : current.map((line) => line.product_id === productId ? { ...line, quantity } : line))
  }

  function addProduct(productId: number) {
    setCart((current) => {
      const existing = current.find((line) => line.product_id === productId)
      return existing
        ? current.map((line) => line.product_id === productId ? { ...line, quantity: line.quantity + 1 } : line)
        : [...current, { product_id: productId, quantity: 1 }]
    })
  }

  async function submitAccount(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      if (accountMode === 'register') {
        const password = String(form.get('password') ?? '')
        if (password !== String(form.get('password_confirmation') ?? '')) {
          setError('The passwords do not match.')
          return
        }
        const email = String(form.get('email') ?? '')
        const registerBranchId = String(form.get('branch_id') ?? '')
        if (!registerBranchId) {
          setError('Choose the clinic you want to register with.')
          return
        }
        const result = await apiRequest<{ message: string }>('/public/storefront/register', {
          method: 'POST',
          body: JSON.stringify({
            organization_slug: organizationSlug,
            branch_id: Number(registerBranchId),
            name: String(form.get('name') ?? ''),
            email,
            phone: String(form.get('phone') ?? '') || null,
            password,
          }),
        })
        setVerificationEmail(email)
        setAccountMode('verify')
        setNotice(result.message)
      } else {
        const result = await apiRequest<{ access_token: string; user: AuthUser }>('/auth/login', {
          method: 'POST',
          body: JSON.stringify({
            email: String(form.get('email') ?? ''),
            password: String(form.get('password') ?? ''),
            device_name: 'Oshel online store',
          }),
        })
        if (!result.user.customer_id) {
          throw new Error('Use a customer account to place an online order.')
        }
        onLogin(result.access_token, result.user)
        setAccountOpen(false)
        if (bookingServiceId && result.user.permissions.includes('appointments.book.self')) {
          setBookingOpen(true)
        }
        setNotice('Signed in. Your cart is ready for checkout.')
      }
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function verifyAccount(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<{ message: string }>('/public/storefront/register/verify', {
        method: 'POST',
        body: JSON.stringify({ email: verificationEmail, code: String(form.get('code') ?? '') }),
      })
      setAccountMode('sign-in')
      setNotice(result.message)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function resendVerificationCode() {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<{ message: string }>('/public/storefront/register/resend', {
        method: 'POST',
        body: JSON.stringify({ email: verificationEmail }),
      })
      setNotice(result.message)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function placeOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!user || !branchId) {
      setAccountOpen(true)
      return
    }
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<EcommerceOrder & { payment_handoff: { status: string; message: string } }>('/portal/orders', {
        method: 'POST',
        body: JSON.stringify({
          organization_slug: organizationSlug,
          branch_id: Number(branchId),
          fulfillment_type: fulfillment,
          coupon_code: couponCode.trim() || null,
          delivery_recipient: String(form.get('delivery_recipient') ?? ''),
          delivery_phone: String(form.get('delivery_phone') ?? ''),
          delivery_address_line1: String(form.get('delivery_address_line1') ?? ''),
          delivery_address_line2: String(form.get('delivery_address_line2') ?? ''),
          delivery_city: String(form.get('delivery_city') ?? ''),
          delivery_instructions: String(form.get('delivery_instructions') ?? ''),
          customer_note: String(form.get('customer_note') ?? ''),
          idempotency_key: crypto.randomUUID(),
          lines: cartLines.map((line) => ({ product_id: line.product.id, quantity: line.quantity })),
        }),
      })
      setOrder(result)
      setPaymentStatus(null)
      setCart([])
      setNotice(result.payment_handoff.message)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function initiateOrderPayment() {
    if (!user || !order) return
    const requestKey = `${order.id}:${selectedPaymentProvider}`
    const idempotencyKey = paymentStatus?.status === 'failed'
      ? crypto.randomUUID()
      : paymentRequestKeys.current.get(requestKey) ?? crypto.randomUUID()
    paymentRequestKeys.current.set(requestKey, idempotencyKey)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<CustomerPayment>(`/portal/orders/${order.id}/payments`, {
        method: 'POST',
        body: JSON.stringify({
          provider: selectedPaymentProvider,
          phone: selectedPaymentProvider === 'mpesa' ? paymentPhone : undefined,
          idempotency_key: idempotencyKey,
        }),
      })
      setPaymentStatus(result)
      setNotice(result.message)
      if (result.redirect_url) window.location.assign(result.redirect_url)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function subscribeNewsletter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNewsletterMessage('')
    try {
      const result = await apiRequest<{ message: string }>('/public/newsletter/subscribe', {
        method: 'POST',
        body: JSON.stringify({
          organization_slug: organizationSlug,
          email: String(form.get('newsletter_email') ?? ''),
          consent: form.get('newsletter_consent') === 'on',
        }),
      })
      setNewsletterMessage(result.message)
      event.currentTarget.reset()
    } catch (requestError) {
      setNewsletterMessage(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function savePrivacyChoice(analyticsAllowed: boolean, marketingAllowed: boolean) {
    const anonymousId = window.localStorage.getItem(`oshel.privacy-id.${organizationSlug}`) ?? crypto.randomUUID()
    window.localStorage.setItem(`oshel.privacy-id.${organizationSlug}`, anonymousId)
    try {
      await apiRequest('/public/privacy-consent', {
        method: 'POST',
        body: JSON.stringify({
          organization_slug: organizationSlug,
          anonymous_id: anonymousId,
          consent_version: '1',
          analytics_allowed: analyticsAllowed,
          marketing_allowed: marketingAllowed,
        }),
      })
      window.localStorage.setItem(`oshel.privacy.${organizationSlug}`, JSON.stringify({ analyticsAllowed, marketingAllowed }))
      setAnalyticsAllowed(analyticsAllowed)
      setPrivacyBannerOpen(false)
    } catch (requestError) {
      setContentError(getErrorMessage(requestError))
    }
  }

  function navigatePublic(event: MouseEvent<HTMLElement>) {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    const target = event.target
    if (!(target instanceof Element)) return
    const anchor = target.closest('a')
    if (!anchor || anchor.target || anchor.hasAttribute('download')) return
    const destination = new URL(anchor.href, window.location.href)
    if (destination.origin !== window.location.origin || destination.hash || destination.pathname === window.location.pathname) return
    event.preventDefault()
    redirectVisits.current.clear()
    window.history.pushState({}, '', `${destination.pathname}${destination.search}`)
    const next = readPublicRoute()
    setPublicPage(next.page)
    setCustomPageSlug(next.customPageSlug)
    setJournalArticleSlug(next.journalArticleSlug)
    setDetailType(next.detailType)
    setDetailId(next.detailId)
    setDetailSlug(next.detailSlug ?? null)
    setPageContent(null)
    if (next.organizationSlug) {
      setOrganizationSlug(next.organizationSlug)
      setPrivacyBannerOpen(!hasStoredPrivacyDecision(next.organizationSlug))
      setAnalyticsAllowed(storedAnalyticsConsent(next.organizationSlug))
    }
    window.scrollTo({ top: 0, behavior: 'instant' })
  }

  function selectOrganization(slug: string) {
    redirectVisits.current.clear()
    setLoading(true)
    setServices([])
    setServicesLoading(true)
    setServicesError('')
    setBranches([])
    setProducts([])
    setHomepage(null)
    setPageContent(null)
    setContentError('')
    setBranchId('')
    setDetailType(null)
    setDetailId(null)
    setOrganizationSlug(slug)
    setPrivacyBannerOpen(Boolean(slug && !hasStoredPrivacyDecision(slug)))
    setAnalyticsAllowed(storedAnalyticsConsent(slug))
    const path = publicPathFor(slug, publicPage, customPageSlug ?? journalArticleSlug)
    window.history.pushState({}, '', path)
  }

  return (
    <main className="storefront-shell" id="public-content" onClick={navigatePublic}>
      <a className="storefront-skip-link" href="#public-content">Skip to content</a>
      <div className="storefront-announcement"><span>OSHEL SKIN CARE</span><span>Thoughtful products. Considered care.</span></div>
      <header className="storefront-header">
        <a className="brand" href={publicPathFor(organizationSlug)} aria-label="Oshel home"><span className="brand-mark">o</span><span>oshel<span className="brand-period">.</span></span></a>
        <nav className="storefront-nav" aria-label="Public website">
          <a href={publicPathFor(organizationSlug, 'home')} aria-current={publicPage === 'home' ? 'page' : undefined}>Home</a>
          <a href={publicPathFor(organizationSlug, 'about')} aria-current={publicPage === 'about' ? 'page' : undefined}>About</a>
          <a href={publicPathFor(organizationSlug, 'services')} aria-current={publicPage === 'services' ? 'page' : undefined}>Services</a>
          <a href={publicPathFor(organizationSlug, 'shop')} aria-current={publicPage === 'shop' ? 'page' : undefined}>Shop</a>
          <a href={publicPathFor(organizationSlug, 'rewards')} aria-current={publicPage === 'rewards' ? 'page' : undefined}>Rewards</a>
          <a href={publicPathFor(organizationSlug, 'testimonials')} aria-current={publicPage === 'testimonials' ? 'page' : undefined}>Testimonials</a>
          <a href={publicPathFor(organizationSlug, 'journal')} aria-current={publicPage === 'journal' ? 'page' : undefined}>Journal</a>
          <a href={publicPathFor(organizationSlug, 'contact')} aria-current={publicPage === 'contact' ? 'page' : undefined}>Contact</a>
        </nav>
        <div className="storefront-header-actions">
          <a className="storefront-booking-link" href={publicPathFor(organizationSlug, 'services')}>Book a visit</a>
          <a className="storefront-staff-link" href="#staff" onClick={(event) => { event.preventDefault(); onStaffLogin() }}>Staff sign in</a>
          <button className="storefront-account-link" type="button" onClick={() => user ? onExit() : setAccountOpen(true)} aria-label={user ? `Open account for ${user.name}` : 'Sign in or create a customer account'}>
            <svg className="storefront-header-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.25" /><path d="M5 20c0-3.7 3.1-6 7-6s7 2.3 7 6" /></svg><span className="storefront-action-label">{user ? 'My account' : 'Account'}</span>
          </button>
          <a className="storefront-bag-link" href={publicPathFor(organizationSlug, 'shop')} aria-label={`Shopping bag, ${bagCount} ${bagCount === 1 ? 'item' : 'items'}`}><svg className="storefront-header-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 8h14l1 13H4L5 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg><span className="storefront-action-label">Bag</span><span className="storefront-bag-count">{bagCount}</span></a>
        </div>
      </header>
      {error && <div className="notice error storefront-notice" role="alert">{error}</div>}
      {notice && <div className="notice success storefront-notice" role="status">{notice}</div>}
      {order && <section className="panel storefront-order-confirmation" aria-live="polite">
        <span className="eyebrow">{order.payment_status === 'paid' ? 'PAYMENT CONFIRMED' : 'ORDER RECEIVED'}</span>
        <h2>{order.order_number}</h2>
        <p>{order.status.replaceAll('_', ' ')} · {money(order.total_amount)} · reservation expires {new Date(order.reservation_expires_at).toLocaleString()}</p>
        <strong>{paymentStatus?.message ?? (order.payment_status === 'paid' ? 'Payment confirmed. Your order is ready for fulfilment.' : 'Payment is required. The order can only be fulfilled after provider verification.')}</strong>
        {order.payment_status === 'pending' && user && <div className="storefront-payment-options">
          {paymentOptions.some((provider) => provider.configured) ? <>
            <label>Payment method<select value={selectedPaymentProvider} onChange={(event) => setSelectedPaymentProvider(event.target.value as CustomerPaymentOption['id'])}>
              {paymentOptions.filter((provider) => provider.configured).map((provider) => <option key={provider.id} value={provider.id}>{provider.label}</option>)}
            </select></label>
            {selectedPaymentProvider === 'mpesa' && <label>M-Pesa phone number<input type="tel" value={paymentPhone} onChange={(event) => setPaymentPhone(event.target.value)} placeholder="07XX XXX XXX" autoComplete="tel" required /></label>}
            <button className="button button-primary" type="button" onClick={() => void initiateOrderPayment()} disabled={busy || (selectedPaymentProvider === 'mpesa' && !paymentPhone.trim())}>
              {busy ? 'Starting secure payment…' : selectedPaymentProvider === 'pesapal' ? 'Continue to secure card checkout' : 'Send M-Pesa payment prompt'}
            </button>
          </> : <p className="muted">Online payment providers are not configured yet. Contact the clinic before placing another order; this order remains reserved until the displayed expiry time.</p>}
        </div>}
        {paymentStatus?.status === 'pending' && paymentStatus.provider === 'mpesa' && <p className="muted">Waiting for M-Pesa confirmation. This page checks the provider status automatically.</p>}
      </section>}
      <div className={publicPage === 'home' ? undefined : 'storefront-home-hidden'}>
      <section className="storefront-hero" id="home">
        <div className="storefront-hero-copy">
          <span className="eyebrow">{homepage?.eyebrow || 'SKINCARE, SELECTED WITH CARE'}</span>
          <h1>{homepage?.title ?? <>Make care<br />a daily ritual.</>}</h1>
          <p>{homepage?.summary || 'Explore thoughtfully selected skincare and in-clinic services, all in one place.'}</p>
          <div className="storefront-hero-actions"><a className="button button-primary" href="#products">Discover products</a><a className="storefront-hero-secondary" href="#services">Explore services <span aria-hidden="true">→</span></a></div>
        </div>
        <SkincarePhotoCarousel variant="hero" />
        <div className="storefront-hero-branch">
        {organizations.length > 1 && <label>Clinic<select value={organizationSlug} onChange={(event) => selectOrganization(event.target.value)}><option value="">Choose a clinic</option>{organizations.map((organization) => <option key={organization.slug} value={organization.slug}>{organization.name}</option>)}</select></label>}
        {branches.length > 0 && <label>Fulfilment branch<select value={branchId} onChange={(event) => { setLoading(true); setServices([]); setServicesLoading(true); setServicesError(''); setBranchId(event.target.value) }}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
        </div>
      </section>
      {contentError && <div className="notice info storefront-notice" role="status">Custom homepage content could not be loaded. Showing the standard Oshel introduction.</div>}
      <section className="storefront-values" aria-label="The Oshel experience">
        <article><span aria-hidden="true">✳</span><strong>Clinic-selected</strong><small>Explore products available from your chosen branch.</small></article>
        <article><span aria-hidden="true">◌</span><strong>Care, your way</strong><small>Browse skincare essentials and treatment services.</small></article>
        <article><span aria-hidden="true">⌖</span><strong>Choose your clinic</strong><small>See availability and service details by location.</small></article>
        <article><span aria-hidden="true">♡</span><strong>Here for your routine</strong><small>Find thoughtful options for your next visit.</small></article>
      </section>
      {publicPromotions.length > 0 && <section className="storefront-promotion-strip" aria-label="Current promotions">{publicPromotions.map((promotion) => <article key={`${promotion.title}-${promotion.coupon_code ?? 'offer'}`}><strong>{promotion.title}</strong>{promotion.message && <span>{promotion.message}</span>}{promotion.coupon_code && <code>{promotion.coupon_code}</code>}</article>)}</section>}
      {productSections.filter((section) => section.products.length > 0).map((section) => <section className="storefront-featured-section" key={section.slug}><div className="storefront-section-heading"><div><span className="eyebrow">CURATED FOR YOU</span><h2>{section.title}</h2>{section.description && <p className="muted">{section.description}</p>}</div></div><div className="storefront-product-grid">{section.products.map((product) => <article className="storefront-product-card" key={`${section.slug}-${product.id}`}>{product.primary_image_url ? <img className="storefront-product-image" src={product.primary_image_url} alt="" loading="lazy" /> : <span className="storefront-product-mark" aria-hidden="true">o</span>}<span className="eyebrow">OSHEL EDIT</span><h3><a href={publicProductPath(organizationSlug, product.id, product.slug)}>{product.name}</a></h3><p className="storefront-description">{product.description || 'A considered addition to your care routine.'}</p><div className="storefront-product-footer"><strong>{money(product.selling_price)}</strong><a className="text-button" href={publicProductPath(organizationSlug, product.id, product.slug)}>View details <span aria-hidden="true">→</span></a></div><button className="button button-secondary button-wide" type="button" disabled={!products.some((available) => available.id === product.id)} onClick={() => addProduct(product.id)}>{products.some((available) => available.id === product.id) ? 'Add to bag' : 'Unavailable at selected clinic'}</button></article>)}</div></section>)}
      <div className="storefront-layout">
        <section className="storefront-catalogue">
          <div className="storefront-section-heading" id="products"><div><span className="eyebrow">{organizationName || 'THE OSHEL SHOP'}</span><h2>Essentials for your routine</h2><p className="muted">Shop products available from your selected clinic.</p></div><label className="storefront-search"><span className="sr-only">Search products</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products…" /></label></div>
          {loading ? <LoadingState /> : visibleProducts.length ? <>
            {productFilters()}
            <div className="storefront-product-grid">{productPages.pageItems.map((product) => productCard(product))}</div>
            <Pagination page={productPages.currentPage} totalPages={productPages.totalPages} total={productPages.total} pageSize={productPages.pageSize} onPage={productPages.setCurrentPage} label="Products pagination" />
          </> : <EmptyState title={products.length ? 'No products match your filters' : 'No products available yet'} message="Clear the filters, choose another branch, or check back when products are published for online shopping." />}
          <section className="storefront-services-section" id="services">
            <div className="storefront-section-heading"><div><span className="eyebrow">IN-CLINIC CARE</span><h2>Services to support you</h2><p className="muted">Explore treatments and care available at your selected branch.</p></div></div>
            {servicesError && <div className="notice error" role="alert">{servicesError}</div>}
            {servicesLoading ? <LoadingState compact /> : visibleServices.length ? <>
              {serviceFilters()}
              <div className="storefront-service-grid">{servicePages.pageItems.map((service) => serviceCard(service))}</div>
              <Pagination page={servicePages.currentPage} totalPages={servicePages.totalPages} total={servicePages.total} pageSize={servicePages.pageSize} onPage={servicePages.setCurrentPage} label="Services pagination" />
            </> : <EmptyState title="No services listed yet" message="Services published by this clinic will appear here. Clear the filters or choose another branch to browse its offerings." />}
          </section>
          <section className="storefront-about-section" id="about">
            <span className="eyebrow">{homepage?.eyebrow || 'THE OSHEL APPROACH'}</span><h2>{homepage?.title || 'Care that feels considered.'}</h2><p>{homepage?.body || 'From daily skincare to in-clinic treatments, explore what is available at your chosen Oshel location and find the next step that feels right for you.'}</p><a className="storefront-hero-secondary" href="#services">Explore our services <span aria-hidden="true">→</span></a>
          </section>
          <section className="storefront-about-section" id="rewards"><span className="eyebrow">OSHEL REWARDS</span><h2>Care that gives back.</h2><p>Earn rewards points on eligible in-clinic purchases, see your balance in your customer account, and redeem points at checkout. Points are recorded only from completed sales.</p><button className="button button-secondary" type="button" onClick={() => user ? onExit() : setAccountOpen(true)}>View customer rewards</button></section>
          <section className="storefront-testimonials-section" id="testimonials"><div className="storefront-section-heading"><div><span className="eyebrow">REAL CUSTOMER EXPERIENCES</span><h2>Kind words, considered care.</h2><p className="muted">Reviews are published after moderation and verified against a completed purchase or visit.</p></div></div>{publicReviews.length ? <div className="storefront-review-grid">{publicReviews.slice(0, 6).map((review) => <article className="storefront-review-card" key={review.id}><div aria-label={`${review.rating} out of 5 stars`}>{'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}</div><p>{review.review_text}</p><strong>{review.display_name}</strong><small>Verified {review.product_name ? `purchase: ${review.product_name}` : `visit: ${review.service_name ?? 'Oshel service'}`}</small></article>)}</div> : <p className="muted">Customer reviews will appear here after they have been approved.</p>}</section>
          <section className="storefront-journal-section" id="journal"><div className="storefront-section-heading"><div><span className="eyebrow">THE SKINCARE JOURNAL</span><h2>Thoughts for your routine.</h2></div></div>{journalArticles.length ? <div className="storefront-journal-grid">{journalArticles.slice(0, 6).map((article) => <article className="storefront-journal-card" key={article.slug}>{article.cover_image_url && <img src={article.cover_image_url} alt="" loading="lazy" />}<span className="eyebrow">{article.eyebrow || 'OSHEL JOURNAL'}</span><h3><a href={publicPathFor(organizationSlug, 'journal', article.slug)}>{article.title}</a></h3><p>{article.summary || article.body}</p><small>{new Date(article.published_at).toLocaleDateString()}</small></article>)}</div> : <p className="muted">Our journal is being prepared. Check back soon.</p>}</section>
          <section className="storefront-newsletter-section"><div><span className="eyebrow">A NOTE FROM OSHEL</span><h2>Thoughtful updates, occasionally.</h2><p>Sign up for skincare notes and news. We’ll ask you to confirm your email before activating your subscription. You can withdraw consent through your customer account or by contacting the clinic.</p></div><form onSubmit={(event) => void subscribeNewsletter(event)}><label>Email address<input name="newsletter_email" type="email" required maxLength={255} autoComplete="email" /></label><label className="storefront-consent-check"><input name="newsletter_consent" type="checkbox" required /> I agree to receive Oshel marketing emails.</label><button className="button button-primary" disabled={busy}>{busy ? 'Submitting…' : 'Subscribe'}</button>{newsletterMessage && <p role="status">{newsletterMessage}</p>}</form></section>
          <section className="storefront-contact-section" id="contact">
            <div><span className="eyebrow">YOUR SELECTED CLINIC</span><h2>{branches.find((branch) => String(branch.id) === branchId)?.name ?? organizationName ?? 'Find your Oshel clinic'}</h2><p>Choose your clinic above to see products and services available at that location. For treatment suitability or delivery details, please contact the clinic directly.</p></div>
            <a className="button button-secondary" href="#home">Choose a clinic <span aria-hidden="true">↑</span></a>
          </section>
        </section>
        <aside className="storefront-cart panel" id="bag" aria-label="Shopping bag">
          <span className="eyebrow">YOUR BAG</span><h2>Order summary</h2>
          {!cartLines.length ? <EmptyState title="Your bag is empty" message="Add an available product to begin." /> : <>
            <div className="storefront-cart-lines">{cartLines.map((line) => <div className="storefront-cart-line" key={line.product.id}><div><strong>{line.product.name}</strong><small>{money(line.product.selling_price)} each</small></div><div className="storefront-quantity"><button type="button" aria-label={`Remove one ${line.product.name}`} onClick={() => setQuantity(line.product.id, line.quantity - 1)}>−</button><span>{line.quantity}</span><button type="button" aria-label={`Add one ${line.product.name}`} disabled={line.quantity >= Number(line.product.available_quantity)} onClick={() => setQuantity(line.product.id, line.quantity + 1)}>+</button></div><strong>{money(Number(line.product.selling_price) * line.quantity)}</strong></div>)}</div>
            <form className="storefront-checkout-form" onSubmit={(event) => void placeOrder(event)}>
              <label>Fulfilment<select value={fulfillment} onChange={(event) => setFulfillment(event.target.value as 'collection' | 'delivery')}><option value="collection">Collect at {branches.find((branch) => String(branch.id) === branchId)?.name ?? 'selected branch'}</option><option value="delivery">Delivery (fee confirmed by clinic)</option></select></label>
              {fulfillment === 'delivery' && <fieldset className="storefront-address"><legend>Delivery details</legend><label>Recipient<input name="delivery_recipient" required maxLength={180} defaultValue={user?.name ?? ''} /></label><label>Phone<input name="delivery_phone" required maxLength={40} /></label><label>Address line 1<input name="delivery_address_line1" required maxLength={255} /></label><label>Address line 2<input name="delivery_address_line2" maxLength={255} /></label><label>City / town<input name="delivery_city" required maxLength={120} /></label><label>Instructions<textarea name="delivery_instructions" maxLength={500} /></label></fieldset>}
              <label>Coupon code<input value={couponCode} onChange={(event) => setCouponCode(event.target.value)} maxLength={40} autoComplete="off" /></label>
              <label>Order note<textarea name="customer_note" maxLength={1000} placeholder="Optional note for the clinic" /></label>
              <div className="storefront-total"><span>Subtotal</span><strong>{money(subtotal)}</strong></div>
              <p className="muted storefront-disclaimer">Delivery fees are confirmed by the clinic. Your order is reserved for 15 minutes and is only fulfilled after payment verification.</p>
              {!user && <button className="button button-secondary button-wide" type="button" onClick={() => setAccountOpen(true)}>Sign in or create a customer account</button>}
              {user && <button className="button button-primary button-wide" type="submit" disabled={busy || !cartLines.length}>{busy ? 'Reserving order…' : 'Reserve order & continue'}</button>}
            </form>
          </>}
        </aside>
      </div>
      </div>
      {publicPage !== 'home' && <div className="storefront-route-page">
        {!detailType && <div className="storefront-route-heading"><span className="eyebrow">{organizationName || 'OSHEL SKIN CARE'}</span><h1>{journalArticleSlug || publicPage === 'custom' ? pageContent?.title || 'Page not found' : ({ about: 'About Oshel', services: 'Services', shop: 'Shop skincare', rewards: 'Oshel Rewards', testimonials: 'Customer stories', journal: 'The skincare journal', contact: 'Contact & locations', custom: pageContent?.title || 'Page not found' } satisfies Record<Exclude<PublicPageKey, 'home'>, string>)[publicPage]}</h1><p className="muted">{pageContent?.summary || ({ about: 'Thoughtful skincare and considered in-clinic care, brought together for your everyday routine.', services: 'Explore the services available from your selected clinic and request a visit.', shop: 'Browse products available at your selected clinic and build your shopping bag.', rewards: 'Earn points on eligible completed purchases and see your balance in your customer account.', testimonials: 'Read customer reviews that have been matched to a completed purchase or visit and approved by Oshel.', journal: 'Practical thoughts and guidance for a more considered skincare routine.', contact: 'Choose your clinic and connect through its published social channels.', custom: 'Discover more from Oshel Skin Care.' } satisfies Record<Exclude<PublicPageKey, 'home'>, string>)[publicPage]}</p></div>}
        {detailType === 'product' && (detailProduct
          ? <article className="storefront-detail storefront-route-card">
            <a className="storefront-back-link" href={publicPathFor(organizationSlug, 'shop')}><span aria-hidden="true">←</span> All products</a>
            <div className="storefront-detail-grid">
              <div className="storefront-detail-gallery">
                <div className="storefront-detail-main-image">{detailImage ? <img src={detailImage} alt={detailProduct.name} /> : <span className="storefront-product-mark" aria-hidden="true">o</span>}</div>
                {(detailProduct.images?.length ?? 0) > 1 && <div className="storefront-detail-thumbs">{detailProduct.images!.map((image) => <button key={image.id} type="button" className={detailImage === image.url ? 'is-active' : ''} onClick={() => setActiveImage(image.url)} aria-label={`Show image ${image.id}`}><img src={image.url} alt="" loading="lazy" /></button>)}</div>}
              </div>
              <div className="storefront-detail-copy">
                <span className="eyebrow">{detailProduct.category_name ?? 'OSHEL CARE'}</span>
                <h1>{detailProduct.name}</h1>
                {detailProduct.brand && <p className="muted">{detailProduct.brand}</p>}
                <p className="storefront-detail-price">{money(detailProduct.selling_price)}</p>
                <p className={Number(detailProduct.available_quantity) > 0 ? 'storefront-stock-in' : 'storefront-stock-out'}>{Number(detailProduct.available_quantity) > 0 ? `${Number(detailProduct.available_quantity)} available` : 'Out of stock'} at {branchLabel || 'the selected clinic'}</p>
                <p className="storefront-detail-description">{detailProduct.description || 'A considered addition to your care routine. Ask the clinic team if you would like guidance on incorporating it into your routine.'}</p>
                <dl className="storefront-detail-facts">
                  <div><dt>SKU</dt><dd>{detailProduct.sku}</dd></div>
                  <div><dt>Category</dt><dd>{detailProduct.category_name ?? '—'}</dd></div>
                  <div><dt>Unit</dt><dd>{detailProduct.unit_of_measure}</dd></div>
                  <div><dt>Clinic</dt><dd>{branchLabel || '—'}</dd></div>
                </dl>
                {detailProductQuantity
                  ? <div className="storefront-quantity storefront-detail-quantity"><button type="button" aria-label={`Remove one ${detailProduct.name}`} onClick={() => setQuantity(detailProduct.id, detailProductQuantity - 1)}>−</button><span>{detailProductQuantity} in bag</span><button type="button" aria-label={`Add one ${detailProduct.name}`} disabled={detailProductQuantity >= Number(detailProduct.available_quantity)} onClick={() => addProduct(detailProduct.id)}>+</button></div>
                  : <button className="button button-primary button-wide" type="button" disabled={Number(detailProduct.available_quantity) < 1} onClick={() => addProduct(detailProduct.id)}>{Number(detailProduct.available_quantity) < 1 ? 'Out of stock' : 'Add to bag'}</button>}
                <div className="storefront-detail-links"><a className="text-button" href={publicPathFor(organizationSlug, 'services')}>Book a skincare consultation <span aria-hidden="true">→</span></a><a className="text-button" href={publicPathFor(organizationSlug, 'contact')}>Ask the clinic <span aria-hidden="true">→</span></a></div>
              </div>
            </div>
            {detailReviews.length > 0 && <section className="storefront-detail-reviews"><h2>Customer reviews</h2><div className="storefront-review-grid">{detailReviews.map((review) => <article className="storefront-review-card" key={review.id}><div aria-label={`${review.rating} out of 5 stars`}>{'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}</div><p>{review.review_text}</p><strong>{review.display_name}</strong><small>Verified purchase</small></article>)}</div></section>}
            {relatedProducts.length > 0 && <section className="storefront-detail-related"><h2>You may also like</h2><div className="storefront-product-grid">{relatedProducts.map((product) => productCard(product))}</div></section>}
          </article>
          : <p className="muted storefront-route-card">{loading || detailResolving ? 'Loading product details…' : 'This product is not available at the selected clinic.'} <a href={publicPathFor(organizationSlug, 'shop')}>Back to shop</a></p>)}
        {detailType === 'service' && (detailService
          ? <article className="storefront-detail storefront-route-card">
            <a className="storefront-back-link" href={publicPathFor(organizationSlug, 'services')}><span aria-hidden="true">←</span> All services</a>
            <div className="storefront-detail-grid">
              <div className="storefront-detail-gallery">
                <div className="storefront-detail-main-image">{detailImage ? <img src={detailImage} alt={detailService.name} /> : <span className="storefront-product-mark" aria-hidden="true">o</span>}</div>
                {(detailService.images?.length ?? 0) > 1 && <div className="storefront-detail-thumbs">{detailService.images!.map((image) => <button key={image.id} type="button" className={detailImage === image.url ? 'is-active' : ''} onClick={() => setActiveImage(image.url)} aria-label={`Show image ${image.id}`}><img src={image.url} alt="" loading="lazy" /></button>)}</div>}
              </div>
              <div className="storefront-detail-copy">
                <span className="eyebrow">{detailService.category?.name ?? detailService.classification.replaceAll('_', ' ')}</span>
                <h1>{detailService.name}</h1>
                <p className="storefront-detail-price">{servicePrice(detailService) !== null ? money(servicePrice(detailService)!) : 'Ask the clinic for pricing'}</p>
                <p className="muted">at {branchLabel || 'the selected clinic'} · {detailService.duration_minutes} minute visit</p>
                <p className="storefront-detail-description">{detailService.description || 'Speak with the clinic team to learn whether this service is suitable for you. They can explain the treatment, aftercare, and what to expect.'}</p>
                <dl className="storefront-detail-facts">
                  <div><dt>Duration</dt><dd>{detailService.duration_minutes} minutes{detailService.buffer_minutes ? ` + ${detailService.buffer_minutes} min buffer` : ''}</dd></div>
                  <div><dt>Category</dt><dd>{detailService.category?.name ?? detailService.classification.replaceAll('_', ' ')}</dd></div>
                  <div><dt>Clinic</dt><dd>{branchLabel || '—'}</dd></div>
                  {detailService.branches[0]?.deposit_policy && <div><dt>Deposit</dt><dd>{detailService.branches[0].deposit_policy.replaceAll('_', ' ')}</dd></div>}
                </dl>
                {detailService.branches.filter((branch) => branch.is_public !== false).length > 1 && <div className="storefront-detail-branches"><h2>Availability by clinic</h2>{detailService.branches.filter((branch) => branch.is_public !== false).map((branch) => <div key={branch.id}><span>{branch.name}</span><strong>{branch.currency_code} {Number(branch.price).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong></div>)}</div>}
                <button className="button button-primary button-wide" type="button" onClick={() => requestServiceBooking(detailService)}>{user?.permissions.includes('appointments.book.self') ? 'Book this service' : user ? 'Contact clinic to book' : 'Sign in to book'}</button>
                <div className="storefront-detail-links"><a className="text-button" href={publicPathFor(organizationSlug, 'contact')}>Ask the clinic <span aria-hidden="true">→</span></a><a className="text-button" href={publicPathFor(organizationSlug, 'shop')}>Shop skincare <span aria-hidden="true">→</span></a></div>
              </div>
            </div>
            {detailReviews.length > 0 && <section className="storefront-detail-reviews"><h2>Customer reviews</h2><div className="storefront-review-grid">{detailReviews.map((review) => <article className="storefront-review-card" key={review.id}><div aria-label={`${review.rating} out of 5 stars`}>{'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}</div><p>{review.review_text}</p><strong>{review.display_name}</strong><small>Verified visit</small></article>)}</div></section>}
            {relatedServices.length > 0 && <section className="storefront-detail-related"><h2>Other services to consider</h2><div className="storefront-service-grid">{relatedServices.map((service) => serviceCard(service))}</div></section>}
          </article>
          : <p className="muted storefront-route-card">{servicesLoading || detailResolving ? 'Loading service details…' : 'This service is not available at the selected clinic.'} <a href={publicPathFor(organizationSlug, 'services')}>Back to services</a></p>)}
        {publicPage === 'about' && <section className="storefront-about-section storefront-route-card"><span className="eyebrow">{pageContent?.eyebrow || 'THE OSHEL APPROACH'}</span><h2>{pageContent?.title || 'Care that feels considered.'}</h2><p>{pageContent?.body || 'Oshel brings carefully selected skincare products and in-clinic services into one welcoming experience. Browse what is available at your chosen location, ask questions, and find a routine that feels right for you.'}</p><div className="storefront-hero-actions"><a className="button button-primary" href={publicPathFor(organizationSlug, 'shop')}>Explore the shop</a><a className="button button-secondary" href={publicPathFor(organizationSlug, 'services')}>Discover services</a></div></section>}
        {publicPage === 'services' && !detailType && <section className="storefront-services-section storefront-route-card"><div className="storefront-section-heading"><div><span className="eyebrow">IN-CLINIC CARE</span><h2>Services to support you</h2><p className="muted">Service availability and pricing are shown for your selected location.</p></div></div>{servicesLoading ? <LoadingState compact /> : visibleServices.length ? <>{serviceFilters()}<div className="storefront-service-grid">{servicePages.pageItems.map((service) => serviceCard(service))}</div><Pagination page={servicePages.currentPage} totalPages={servicePages.totalPages} total={servicePages.total} pageSize={servicePages.pageSize} onPage={servicePages.setCurrentPage} label="Services pagination" /></> : <EmptyState title="No services listed yet" message="Services published by the clinic will appear here. Clear the filters or choose another branch." />}</section>}
        {publicPage === 'shop' && !detailType && <div className="storefront-layout storefront-route-card"><section className="storefront-catalogue"><div className="storefront-section-heading"><div><span className="eyebrow">{organizationName || 'THE OSHEL SHOP'}</span><h2>Essentials for your routine</h2></div><label className="storefront-search"><span className="sr-only">Search products</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products…" /></label></div>{visibleProducts.length ? <>{productFilters()}<div className="storefront-product-grid">{productPages.pageItems.map((product) => productCard(product))}</div><Pagination page={productPages.currentPage} totalPages={productPages.totalPages} total={productPages.total} pageSize={productPages.pageSize} onPage={productPages.setCurrentPage} label="Products pagination" /></> : <EmptyState title="No products available yet" message="Clear the filters, choose another branch, or check back when products are published for online shopping." />}</section><aside className="storefront-cart panel" aria-label="Shopping bag"><span className="eyebrow">YOUR BAG</span><h2>Order summary</h2>{!cartLines.length ? <EmptyState title="Your bag is empty" message="Add an available product to begin." /> : <><div className="storefront-cart-lines">{cartLines.map((line) => <div className="storefront-cart-line" key={line.product.id}><div><strong>{line.product.name}</strong><small>{money(line.product.selling_price)} each</small></div><div className="storefront-quantity"><button type="button" aria-label={`Remove one ${line.product.name}`} onClick={() => setQuantity(line.product.id, line.quantity - 1)}>−</button><span>{line.quantity}</span><button type="button" aria-label={`Add one ${line.product.name}`} onClick={() => setQuantity(line.product.id, line.quantity + 1)}>+</button></div><strong>{money(Number(line.product.selling_price) * line.quantity)}</strong></div>)}</div><form className="storefront-checkout-form" onSubmit={(event) => void placeOrder(event)}><label>Fulfilment<select value={fulfillment} onChange={(event) => setFulfillment(event.target.value as 'collection' | 'delivery')}><option value="collection">Collection</option><option value="delivery">Delivery (fee confirmed by clinic)</option></select></label>{fulfillment === 'delivery' && <fieldset className="storefront-address"><legend>Delivery details</legend><label>Recipient<input name="delivery_recipient" required maxLength={180} defaultValue={user?.name ?? ''} /></label><label>Phone<input name="delivery_phone" required maxLength={40} /></label><label>Address line 1<input name="delivery_address_line1" required maxLength={255} /></label><label>Address line 2<input name="delivery_address_line2" maxLength={255} /></label><label>City / town<input name="delivery_city" required maxLength={120} /></label><label>Instructions<textarea name="delivery_instructions" maxLength={500} /></label></fieldset>}<label>Coupon code<input value={couponCode} onChange={(event) => setCouponCode(event.target.value)} maxLength={40} autoComplete="off" /></label><label>Order note<textarea name="customer_note" maxLength={1000} placeholder="Optional note for the clinic" /></label><div className="storefront-total"><span>Subtotal</span><strong>{money(subtotal)}</strong></div><p className="muted storefront-disclaimer">Delivery fees are confirmed by the clinic. Your order is reserved for 15 minutes and is only fulfilled after payment verification.</p>{!user && <button className="button button-secondary button-wide" type="button" onClick={() => setAccountOpen(true)}>Sign in or create a customer account</button>}{user && <button className="button button-primary button-wide" type="submit" disabled={busy}>{busy ? 'Reserving order…' : 'Reserve order & continue'}</button>}</form></>}</aside></div>}
        {publicPage === 'rewards' && <section className="storefront-about-section storefront-route-card"><span className="eyebrow">OSHEL REWARDS</span><h2>Care that gives back.</h2><p>Earn points from eligible completed in-clinic purchases. Rewards are credited only after a POS sale completes; refunds and voids reverse the associated points. Sign in to view your account balance and ledger.</p><button className="button button-primary" type="button" onClick={() => user ? onExit() : setAccountOpen(true)}>{user ? 'Open my rewards account' : 'Sign in to view rewards'}</button></section>}
        {publicPage === 'testimonials' && <section className="storefront-testimonials-section storefront-route-card"><span className="eyebrow">REAL CUSTOMER EXPERIENCES</span><h2>Kind words, considered care.</h2><p className="muted">Reviews are published after moderation and verified against a completed purchase or visit.</p>{publicReviews.length ? <><div className="storefront-filter-bar"><div className="storefront-filter-chips" role="group" aria-label="Filter reviews by rating"><button type="button" className={reviewRating === '' ? 'is-active' : ''} onClick={() => setReviewRating('')} aria-pressed={reviewRating === ''}>All ratings</button>{[5, 4, 3, 2, 1].map((rating) => <button type="button" key={rating} className={reviewRating === String(rating) ? 'is-active' : ''} onClick={() => setReviewRating(String(rating))} aria-pressed={reviewRating === String(rating)}>{rating} ★</button>)}</div></div>{visibleReviews.length ? <><div className="storefront-review-grid">{reviewPages.pageItems.map((review) => <article className="storefront-review-card" key={review.id}><div aria-label={`${review.rating} out of 5 stars`}>{'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}</div><p>{review.review_text}</p><strong>{review.display_name}</strong><small>Verified {review.product_name ? `purchase: ${review.product_name}` : `visit: ${review.service_name ?? 'Oshel service'}`}</small></article>)}</div><Pagination page={reviewPages.currentPage} totalPages={reviewPages.totalPages} total={reviewPages.total} pageSize={reviewPages.pageSize} onPage={reviewPages.setCurrentPage} label="Reviews pagination" /></> : <p className="muted">No approved reviews match this rating yet.</p>}</> : <p className="muted">Customer reviews will appear here after they have been approved.</p>}</section>}
        {publicPage === 'journal' && (journalArticleSlug
          ? pageContent
            ? <article className="storefront-journal-card storefront-route-card">{pageContent.cover_image_url && <img src={pageContent.cover_image_url} alt="" />}<span className="eyebrow">{pageContent.eyebrow || 'OSHEL JOURNAL'}</span><h2>{pageContent.title}</h2>{pageContent.summary && <p>{pageContent.summary}</p>}<p>{pageContent.body}</p></article>
            : <p className="muted storefront-route-card">This journal article is not available.</p>
          : <section className="storefront-journal-section storefront-route-card"><span className="eyebrow">THE SKINCARE JOURNAL</span><h2>Thoughts for your routine.</h2><label className="storefront-search storefront-journal-search"><span className="sr-only">Search journal</span><input value={journalSearch} onChange={(event) => setJournalSearch(event.target.value)} placeholder="Search journal…" /></label>{visibleJournal.length ? <><div className="storefront-journal-grid">{journalPages.pageItems.map((article) => <article className="storefront-journal-card" key={article.slug}>{article.cover_image_url && <img src={article.cover_image_url} alt="" loading="lazy" />}<span className="eyebrow">{article.eyebrow || 'OSHEL JOURNAL'}</span><h2><a href={publicPathFor(organizationSlug, 'journal', article.slug)}>{article.title}</a></h2><p>{article.summary}</p><small>{new Date(article.published_at).toLocaleDateString()}</small></article>)}</div><Pagination page={journalPages.currentPage} totalPages={journalPages.totalPages} total={journalPages.total} pageSize={journalPages.pageSize} onPage={journalPages.setCurrentPage} label="Journal pagination" /></> : <p className="muted">No journal articles match your search. Clear the search to see all articles.</p>}</section>)}
        {publicPage === 'custom' && (pageContent
          ? <article className="storefront-about-section storefront-route-card">{pageContent.cover_image_url && <img src={pageContent.cover_image_url} alt="" />}<span className="eyebrow">{pageContent.eyebrow || organizationName}</span><h2>{pageContent.title}</h2><p>{pageContent.body}</p></article>
          : <p className="muted storefront-route-card">This page is not available.</p>)}
        {publicPage === 'contact' && <section className="storefront-contact-section storefront-route-card"><div><span className="eyebrow">YOUR SELECTED CLINIC</span><h2>{branches.find((branch) => String(branch.id) === branchId)?.name ?? organizationName ?? 'Find your Oshel clinic'}</h2><p>Choose your clinic to see products and services available at that location. For treatment suitability or delivery details, please contact the clinic directly.</p>{organizations.length > 1 && <label>Clinic<select value={organizationSlug} onChange={(event) => selectOrganization(event.target.value)}>{organizations.map((organization) => <option key={organization.slug} value={organization.slug}>{organization.name}</option>)}</select></label>}{branches.length > 1 && <label>Location<select value={branchId} onChange={(event) => setBranchId(event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}<div className="storefront-social-links">{socialLinks.map((link) => <a href={link.url} key={link.platform} target="_blank" rel="noreferrer">{link.platform}</a>)}</div></div><PublicEnquiryForm key={`${organizationSlug}-${branchId}`} organizationSlug={organizationSlug} branchId={branchId} /></section>}
      </div>}
      <footer className="storefront-footer">
        <a className="brand" href={publicPathFor(organizationSlug)}>oshel<span className="brand-period">.</span></a>
        <nav className="storefront-footer-nav" aria-label="Footer navigation">
          <a href={publicPathFor(organizationSlug, 'shop')}>Shop products</a>
          <a href={publicPathFor(organizationSlug, 'services')}>Explore services</a>
          <a href={publicPathFor(organizationSlug, 'about')}>Our approach</a>
          <a href={publicPathFor(organizationSlug, 'contact')}>Visit us</a>
          <a href={publicPathFor(organizationSlug, 'rewards')}>Oshel Rewards</a>
          <a href={publicPathFor(organizationSlug, 'testimonials')}>Testimonials</a>
          <a href={publicPathFor(organizationSlug, 'journal')}>Journal</a>
        </nav>
        <div className="storefront-social-links">{socialLinks.map((link) => <a href={link.url} key={link.platform} target="_blank" rel="noreferrer">{link.platform}</a>)}</div>
        <small>© Oshel Skin Care</small>
      </footer>
      {privacyBannerOpen && organizationSlug && <aside className="privacy-consent-banner" aria-label="Privacy choices"><div><strong>Your privacy choices</strong><p>Optional analytics and marketing integrations remain off unless you choose to allow them. No tracking scripts are enabled by this consent record.</p></div><div><button className="button button-secondary" type="button" onClick={() => void savePrivacyChoice(false, false)}>Reject optional</button><button className="button button-primary" type="button" onClick={() => void savePrivacyChoice(true, true)}>Allow optional</button></div></aside>}
      {accountOpen && !user && <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setAccountOpen(false) }}>
        <section className="dialog storefront-account-dialog" role="dialog" aria-modal="true" aria-labelledby="store-account-title">
          <button className="dialog-close" type="button" onClick={() => setAccountOpen(false)} aria-label="Close account form">×</button>
          <span className="eyebrow">CUSTOMER ACCOUNT</span><h2 id="store-account-title">{accountMode === 'verify' ? 'Verify your email' : accountMode === 'register' ? 'Create your account' : 'Sign in to checkout'}</h2>
          <p className="muted">{accountMode === 'verify' ? `Enter the six-digit code we sent to ${verificationEmail || 'your email address'}.` : accountMode === 'register' ? 'We email a six-digit code to confirm your address before you can shop.' : 'Online checkout requires a verified customer account.'}</p>
          {error && <div className="notice error" role="alert">{error}</div>}{notice && <div className="notice success" role="status">{notice}</div>}
          {accountMode === 'verify'
            ? <>
              <form className="form-stack" onSubmit={(event) => void verifyAccount(event)}>
                <label>Verification code<input name="code" required inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="one-time-code" autoFocus /></label>
                <button className="button button-primary button-wide" type="submit" disabled={busy}>{busy ? 'Verifying…' : 'Verify email'}</button>
              </form>
              <button className="text-button auth-switch" type="button" disabled={busy} onClick={() => void resendVerificationCode()}>Resend code</button>
              <button className="text-button auth-switch" type="button" onClick={() => { setAccountMode('sign-in'); setError(''); setNotice('') }}>Back to sign in</button>
            </>
            : <>
              <form className="form-stack" onSubmit={(event) => void submitAccount(event)}>
                {accountMode === 'register' && <><label>Name<input name="name" required maxLength={255} autoComplete="name" /></label><label>Phone<input name="phone" type="tel" maxLength={40} autoComplete="tel" /></label><label>Preferred clinic<select name="branch_id" required value={branchId} onChange={(event) => setBranchId(event.target.value)} disabled={branches.length === 0}>{branches.length === 0 ? <option value="">Loading clinics…</option> : branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label></>}
                <label>Email<input name="email" type="email" required maxLength={255} autoComplete="email" defaultValue={accountMode === 'register' ? verificationEmail : ''} /></label>
                <label>Password<input name="password" type="password" required minLength={8} autoComplete={accountMode === 'register' ? 'new-password' : 'current-password'} /></label>
                {accountMode === 'register' && <label>Confirm password<input name="password_confirmation" type="password" required minLength={8} autoComplete="new-password" /></label>}
                <button className="button button-primary button-wide" type="submit" disabled={busy || (accountMode === 'register' && !branchId)}>{busy ? 'Please wait…' : accountMode === 'register' && !branchId ? 'Loading clinic…' : accountMode === 'register' ? 'Create account' : 'Sign in'}</button>
              </form>
              <button className="text-button auth-switch" type="button" onClick={() => { setAccountMode(accountMode === 'register' ? 'sign-in' : 'register'); setError(''); setNotice('') }}>{accountMode === 'register' ? 'Already have an account? Sign in' : 'New to Oshel? Create an account'}</button>
            </>}
        </section>
      </div>}
      {bookingOpen && user?.permissions.includes('appointments.book.self') && branchId && <PortalAppointmentBookingDialog
        branchId={Number(branchId)}
        initialServiceId={bookingServiceId}
        onClose={() => { setBookingOpen(false); setBookingServiceId(null) }}
        onSaved={() => { setBookingOpen(false); setBookingServiceId(null); setNotice('Your appointment request has been submitted.') }}
        onWaitlisted={() => { setBookingOpen(false); setBookingServiceId(null); setNotice('You have been added to the service waitlist.') }}
      />}
    </main>
  )
}

function PasswordField({
  name,
  label,
  autoComplete,
}: {
  name: string
  label: string
  autoComplete: string
}) {
  const [visible, setVisible] = useState(false)

  return (
    <label className="field">
      <span>{label}</span>
      <span className="password-input">
        <input
          type={visible ? 'text' : 'password'}
          name={name}
          autoComplete={autoComplete}
          minLength={name === 'password' && autoComplete !== 'current-password' ? 12 : undefined}
          required
        />
        <button
          className="password-toggle"
          type="button"
          aria-label={visible ? 'Hide password' : 'Show password'}
          onClick={() => setVisible(!visible)}
        >
          {visible ? 'Hide' : 'Show'}
        </button>
      </span>
    </label>
  )
}

function ChangePasswordScreen({
  onComplete,
  onLogout,
}: {
  onComplete: () => void
  onLogout: () => void
}) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)
    const form = new FormData(event.currentTarget)

    try {
      await apiRequest('/auth/password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: form.get('current_password'),
          password: form.get('password'),
          password_confirmation: form.get('password_confirmation'),
        }),
      })
      onComplete()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="password-page">
      <div className="password-card">
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">o</span>
          <span>oshel<span className="brand-period">.</span></span>
        </div>
        <span className="eyebrow">ONE MORE STEP</span>
        <h1>Secure your account</h1>
        <p className="muted">Set a personal password before entering your workspace.</p>
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="form-stack" onSubmit={submit}>
          <PasswordField name="current_password" label="Temporary password" autoComplete="current-password" />
          <PasswordField name="password" label="New password" autoComplete="new-password" />
          <PasswordField name="password_confirmation" label="Confirm new password" autoComplete="new-password" />
          <p className="field-hint">At least 12 characters with upper and lower case, a number, and a symbol.</p>
          <button className="button button-primary button-wide" disabled={busy}>
            {busy ? 'Updating…' : 'Set new password'}
          </button>
        </form>
        <button className="text-button auth-switch" type="button" onClick={onLogout}>Sign out</button>
      </div>
    </main>
  )
}

function VerificationScreen({
  email,
  onSignOut,
}: {
  email: string
  onSignOut: () => void
}) {
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function resendVerification() {
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest<{ message: string }>('/public/storefront/register/resend', {
        method: 'POST',
        body: JSON.stringify({ email }),
      })
      setNotice(response.message)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function verifyCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest<{ message: string }>('/public/storefront/register/verify', {
        method: 'POST',
        body: JSON.stringify({ email, code: String(form.get('code') ?? '') }),
      })
      setNotice(`${response.message} Reloading your workspace…`)
      window.setTimeout(() => window.location.reload(), 1200)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="password-page">
      <section className="password-card">
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">o</span>
          <span>oshel<span className="brand-period">.</span></span>
        </div>
        <span className="eyebrow">VERIFY YOUR EMAIL</span>
        <h1>One quick check</h1>
        <p className="muted">Enter the six-digit code we email to <strong>{email}</strong> to unlock your secure clinic workspace.</p>
        {notice && <div className="notice success" role="status">{notice}</div>}
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="form-stack" onSubmit={(event) => void verifyCode(event)}>
          <label>Verification code<input name="code" required inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="one-time-code" /></label>
          <button className="button button-primary button-wide" type="submit" disabled={busy}>{busy ? 'Verifying…' : 'Verify and continue'}</button>
        </form>
        <button className="text-button auth-switch" type="button" disabled={busy} onClick={() => void resendVerification()}>
          {busy ? 'Sending…' : 'Send a new code'}
        </button>
        <button className="text-button auth-switch" type="button" onClick={onSignOut}>Sign out</button>
      </section>
    </main>
  )
}

function App() {
  const [token, setToken] = useState<string | null>(null)
  const [user, setUser] = useState<AuthUser | null>(null)
  const [page, setPage] = useState<PageKey>('overview')
  const [pageSection, setPageSection] = useState('')
  const [staffPage, setStaffPage] = useState(1)
  const [expandedModules, setExpandedModules] = useState<string[]>([])
  const [overview, setOverview] = useState<Overview | null>(null)
  const [pageData, setPageData] = useState<unknown>(null)
  const [selectedBranch, setSelectedBranch] = useState('all')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const [authNotice, setAuthNotice] = useState('')
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [expandedNavigationGroups, setExpandedNavigationGroups] = useState<string[]>(
    navigationGroups.map((group) => group.key),
  )
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [inboxUnread, setInboxUnread] = useState(0)
  const [staffCreateRequest, setStaffCreateRequest] = useState(0)
  const [handledStaffCreateRequest, setHandledStaffCreateRequest] = useState(0)
  const [storefrontOpen, setStorefrontOpen] = useState(
    () => window.location.hash !== '#staff' && !window.location.hash.startsWith('#staff/'),
  )
  const pageRequestGeneration = useRef(0)

  const visibleNavigation = useMemo(
    () => filterNavigationByPermissions(navigation, user?.permissions ?? []) as NavigationItem[],
    [user],
  )
  const visibleNavigationGroups = useMemo(
    () => navigationGroups
      .map((group) => ({
        ...group,
        items: visibleNavigation.filter((item) => group.pages.includes(item.key)),
      }))
      .filter((group) => group.items.length > 0),
    [visibleNavigation],
  )
  const hasGlobalBranchAccess = user?.permissions.includes('branch.view_all') ?? false
  const canAddStaff = Boolean(
    user?.permissions.includes('users.manage') && user?.permissions.includes('roles.assign'),
  )

  function openAddStaff() {
    setStaffCreateRequest((request) => request + 1)
    navigateToPage('users')
    setMobileNavOpen(false)
  }

  useEffect(() => {
    const syncPublicRoute = () => {
      if (window.location.hash === '#staff' || window.location.hash.startsWith('#staff/')) {
        setStorefrontOpen(false)
        const route = parseStaffRoute(window.location.hash)
        if (
          route &&
          (!user || visibleNavigationForUser(user.permissions).some((item) => item.key === route.page))
        ) {
          if (route.page !== page) {
            pageRequestGeneration.current += 1
            setPageData(null)
            setStaffPage(1)
            setError('')
          }
          setPage(route.page)
          setPageSection(user ? resolveStaffSection(route.page, route.section, user.permissions) : route.section)
          const groupKey = navigationGroupKey(route.page)
          if (groupKey) {
            setExpandedNavigationGroups((expanded) => expanded.includes(groupKey) ? expanded : [...expanded, groupKey])
          }
          if (staffSections[route.page]?.length) {
            setExpandedModules((expanded) => expanded.includes(route.page) ? expanded : [...expanded, route.page])
          }
        }
      }
      else if (window.location.hash.startsWith('#/')) setStorefrontOpen(true)
    }
    window.addEventListener('hashchange', syncPublicRoute)
    return () => window.removeEventListener('hashchange', syncPublicRoute)
  }, [page, user])

  useEffect(() => {
    setApiAccessToken(token)
  }, [token])

  useEffect(() => {
    if (!user || !token || user.customer_id) return
    let active = true
    const refresh = () => {
      void apiRequest<{ unread_count: number }>('/notifications/unread-count')
        .then((result) => {
          if (active) setInboxUnread(result.unread_count)
        })
        .catch(() => undefined)
    }
    refresh()
    const interval = window.setInterval(refresh, 60000)
    return () => {
      active = false
      window.clearInterval(interval)
    }
  }, [user, token])

  useEffect(() => {
    const url = new URL(window.location.href)
    const result = url.searchParams.get('marketing_integration')
    if (!result) return
    const messages: Record<string, string> = {
      connected: 'Marketing platform authorization completed. Publishing and sync capabilities are still manual-only.',
      authorization_denied: 'The provider authorization was cancelled or denied.',
      provider_unreachable: 'The provider could not be reached. You can retry authorization.',
      token_exchange_failed: 'The provider did not complete authorization. No credentials were stored.',
      invalid_state: 'The authorization request expired or was already used. Please try again.',
      account_unavailable: 'The staff account is no longer active. Contact an administrator.',
      invalid_callback: 'The provider returned an incomplete authorization response.',
    }
    setToast(messages[result] ?? 'Marketing platform authorization did not complete.')
    url.searchParams.delete('marketing_integration')
    url.searchParams.delete('platform')
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`)
  }, [])

  useEffect(() => {
    let robots = document.querySelector<HTMLMetaElement>('meta[name="robots"]')
    if (!robots) {
      robots = document.createElement('meta')
      robots.name = 'robots'
      document.head.append(robots)
    }
    robots.content = storefrontOpen ? 'index,follow' : 'noindex,nofollow'
  }, [storefrontOpen])

  const loadPage = useCallback(async () => {
    if (!user || !token) return

    const requestGeneration = ++pageRequestGeneration.current
    await Promise.resolve()
    if (requestGeneration !== pageRequestGeneration.current) return
    const pageNavigation = visibleNavigation.find((item) => item.key === page)
    if (!pageNavigation) {
      return
    }

    setLoading(true)
    setError('')

    const pagedSuffix = staffPage > 1 ? `?page=${staffPage}` : ''

    try {
      let data: unknown
      if (page === 'overview') {
        data = await apiRequest<Overview>('/overview')
      } else if (page === 'analytics') {
        const branchQuery = selectedBranch === 'all' ? '' : `?branch_id=${encodeURIComponent(selectedBranch)}`
        data = await apiRequest<AnalyticsDashboard>(`/analytics/dashboard${branchQuery}`)
      } else if (page === 'branches') {
        data = await apiRequest<Branch[]>('/branches')
      } else if (page === 'departments') {
        data = await apiRequest<Department[]>('/departments')
      } else if (page === 'users') {
        data = await apiRequest<Paginated<ApiUser>>(`/users${pagedSuffix}`)
      } else if (page === 'roles') {
        data = await apiRequest<RoleList>('/roles')
      } else if (page === 'customers') {
        data = await apiRequest<Paginated<CustomerRecord>>(`/customers${pagedSuffix}`)
      } else if (page === 'services') {
        data = await apiRequest<ServiceRecord[]>('/services')
      } else if (page === 'appointments') {
        data = await apiRequest<Paginated<AppointmentRecord>>(`/appointments${pagedSuffix}`)
      } else if (page === 'clinical') {
        data = await apiRequest<Paginated<ClinicalEncounterRecord>>(`/clinical/encounters${pagedSuffix}`)
      } else if (page === 'hr') {
        const [employees, shifts, attendance, leaveRequests] = await Promise.all([
          apiRequest<Paginated<EmployeeRecord>>('/hr/employees'),
          apiRequest<Paginated<Record<string, unknown>>>('/hr/shifts'),
          apiRequest<Paginated<Record<string, unknown>>>('/hr/attendance'),
          apiRequest<Paginated<Record<string, unknown>>>('/hr/leave-requests'),
        ])
        data = { employees, shifts, attendance, leaveRequests }
      } else if (page === 'payroll') {
        const [runs, branches, employees, components, commissions] = await Promise.all([
          apiRequest<Paginated<PayrollRunRecord>>('/payroll/runs'),
          apiRequest<Branch[]>('/branches'),
          user.permissions.includes('hr.view')
            ? apiRequest<Paginated<EmployeeRecord>>('/hr/employees')
            : Promise.resolve({ data: [], total: 0 }),
          user.permissions.includes('payroll.config.manage')
            ? apiRequest<Record<string, unknown>[]>('/payroll/components')
            : Promise.resolve([]),
          user.permissions.includes('payroll.commission.manage')
            ? apiRequest<Paginated<Record<string, unknown>>>('/payroll/commissions')
            : Promise.resolve({ data: [], total: 0 }),
        ])
        data = { runs, branches, employees, components, commissions }
      } else if (page === 'inventory') {
        data = await apiRequest<InventoryOverview>('/inventory/overview')
      } else if (page === 'procurement') {
        const [procurement, inventory] = await Promise.all([
          apiRequest<ProcurementOverview>('/procurement/overview'),
          user.permissions.includes('inventory.view')
            ? apiRequest<InventoryOverview>('/inventory/overview')
            : Promise.resolve(null),
        ])
        data = { procurement, inventory }
      } else if (page === 'pos') {
        data = await apiRequest<PosOverview>('/pos/overview')
      } else if (page === 'pos-management') {
        data = null
      } else if (page === 'ecommerce') {
        data = await apiRequest<EcommerceOrder[]>('/ecommerce/orders')
      } else if (page === 'payments') {
        data = await apiRequest<{ data: PaymentReconciliationRow[] }>('/payments/reconciliation')
      } else if (page === 'accounting') {
        data = await apiRequest<AccountingWorkspace>('/accounting/overview')
      } else if (page === 'crm') {
        data = await apiRequest<CrmWorkspace>('/crm/overview')
      } else if (page === 'website') {
        data = await apiRequest<Paginated<WebsitePage>>('/website/pages')
      } else if (page === 'growth') {
        const permissions = user.permissions
        const [loyalty, reviews, sections, promotions, socialLinks, products] = await Promise.all([
          permissions.includes('loyalty.manage') ? apiRequest<WebsiteGrowthData['loyalty']>('/website/loyalty') : Promise.resolve(undefined),
          permissions.includes('website.reviews.manage') ? apiRequest<WebsiteGrowthData['reviews']>('/website/reviews') : Promise.resolve(undefined),
          permissions.includes('website.merchandising.manage') ? apiRequest<WebsiteGrowthData['sections']>('/website/product-sections') : Promise.resolve(undefined),
          permissions.includes('website.marketing.manage') ? apiRequest<WebsiteGrowthData['promotions']>('/website/promotions') : Promise.resolve(undefined),
          permissions.includes('website.social.manage') ? apiRequest<WebsiteGrowthData['socialLinks']>('/website/social-links') : Promise.resolve(undefined),
          permissions.includes('website.merchandising.manage') ? apiRequest<WebsiteGrowthData['products']>('/website/products') : Promise.resolve(undefined),
        ])
        data = {
          loyalty,
          reviews,
          sections,
          promotions,
          socialLinks,
          products,
        } satisfies WebsiteGrowthData
      } else if (page === 'marketing') {
        const permissions = user.permissions
        const branchQuery = selectedBranch === 'all' ? '' : `?branch_id=${encodeURIComponent(selectedBranch)}`
        const [integrations, campaigns, posts, seo] = await Promise.all([
          permissions.includes('marketing.integrations.view')
            ? apiRequest<NonNullable<MarketingCenterData['integrations']>>(`/marketing/integrations${branchQuery}`)
            : Promise.resolve(undefined),
          permissions.includes('marketing.campaigns.view')
            ? apiRequest<NonNullable<MarketingCenterData['campaigns']>>(`/marketing/external-campaigns${branchQuery}`)
            : Promise.resolve(undefined),
          permissions.includes('marketing.social.view')
            ? apiRequest<NonNullable<MarketingCenterData['posts']>>(`/marketing/social-posts${branchQuery}`)
            : Promise.resolve(undefined),
          permissions.includes('marketing.seo.view')
            ? apiRequest<NonNullable<MarketingCenterData['seo']>>(`/marketing/seo${branchQuery}`)
            : Promise.resolve(undefined),
        ])
        data = { integrations, campaigns, posts, seo } satisfies MarketingCenterData
      } else if (page === 'organization') {
        const organizations = await apiRequest<OrganizationRecord[]>('/organizations')
        data = organizations.find((organization) => organization.id === user.organization_id) ?? organizations[0] ?? null
      } else if (page === 'email') {
        data = await apiRequest<EmailSettingsRecord>('/settings/email')
      } else if (page === 'notifications') {
        data = await apiRequest<NotificationSettingsRecord>('/settings/notifications')
      } else if (page === 'inbox') {
        data = null
      } else if (page === 'account') {
        data = await apiRequest<AuthSessionRecord[]>('/auth/sessions')
      } else {
        data = await apiRequest<Paginated<AuditEvent>>('/audit-events')
      }
      if (requestGeneration !== pageRequestGeneration.current) return
      if (page === 'overview') setOverview(data as Overview)
      setPageData(data)
    } catch (requestError) {
      if (requestGeneration !== pageRequestGeneration.current) return
      if (requestError instanceof ApiError && requestError.status === 401) {
        setApiAccessToken(null)
        setToken(null)
        setUser(null)
        setAuthNotice('Your session expired. Please sign in again.')
        return
      }
      setError(getErrorMessage(requestError))
    } finally {
      if (requestGeneration === pageRequestGeneration.current) setLoading(false)
    }
  }, [page, selectedBranch, staffPage, token, user, visibleNavigation])

  function navigateToPage(nextPage: PageKey, nextSection?: string) {
    if (nextPage !== page) {
      pageRequestGeneration.current += 1
      setPageData(null)
      setStaffPage(1)
      setError('')
    }
    setPage(nextPage)
    const permissions = user?.permissions ?? []
    const section = resolveStaffSection(
      nextPage,
      nextSection ?? (nextPage === page ? pageSection : ''),
      permissions,
    )
    setPageSection(section)
    const groupKey = navigationGroupKey(nextPage)
    if (groupKey) setExpandedNavigationGroups((expanded) => expanded.includes(groupKey) ? expanded : [...expanded, groupKey])
    if (staffSections[nextPage]?.length) {
      setExpandedModules((expanded) => expanded.includes(nextPage) ? expanded : [...expanded, nextPage])
    }
    const nextHash = section ? `#staff/${nextPage}/${section}` : `#staff/${nextPage}`
    if (window.location.hash !== nextHash) {
      window.location.hash = nextHash
    }
  }

  function toggleStaffModule(key: PageKey) {
    setExpandedModules((expanded) =>
      expanded.includes(key) ? expanded.filter((entry) => entry !== key) : [...expanded, key])
  }

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadPage()
    }, 0)
    return () => {
      window.clearTimeout(timeout)
      pageRequestGeneration.current += 1
    }
  }, [loadPage])

  useEffect(() => {
    if (!user || !token || overview) return
    if (!user.permissions.includes('organization.view') || !user.permissions.includes('branch.view')) return
    let active = true
    void apiRequest<Overview>('/overview')
      .then((result) => {
        if (active) setOverview(result)
      })
      .catch(() => undefined)
    return () => { active = false }
  }, [overview, token, user])

  useEffect(() => {
    if (!toast) return
    const timeout = window.setTimeout(() => setToast(''), 3500)
    return () => window.clearTimeout(timeout)
  }, [toast])

  function signIn(nextToken: string, nextUser: AuthUser) {
    setApiAccessToken(nextToken)
    setToken(nextToken)
    setUser(nextUser)
    const allowedNavigation = visibleNavigationForUser(nextUser.permissions)
    const firstAllowedPage = allowedNavigation[0]
    const preferredPage = nextUser.roles
      .map((role) => roleLandingPages[role])
      .find((candidate): candidate is PageKey => Boolean(candidate))
    const requestedRoute = parseStaffRoute(window.location.hash)
    const requestedPage = requestedRoute?.page
    const initialPage = requestedPage && allowedNavigation.some((item) => item.key === requestedPage)
      ? requestedPage
      : preferredPage && allowedNavigation.some((item) => item.key === preferredPage)
        ? preferredPage
        : firstAllowedPage?.key ?? 'overview'
    const initialSection = resolveStaffSection(initialPage, requestedRoute?.section ?? '', nextUser.permissions)
    setPage(initialPage)
    setPageSection(initialSection)
    setStaffPage(1)
    const groupKey = navigationGroupKey(initialPage)
    if (groupKey) setExpandedNavigationGroups((expanded) => expanded.includes(groupKey) ? expanded : [...expanded, groupKey])
    if (staffSections[initialPage]?.length) {
      setExpandedModules((expanded) => expanded.includes(initialPage) ? expanded : [...expanded, initialPage])
    }
    window.history.replaceState({}, '', `${window.location.pathname}${window.location.search}${initialSection ? `#staff/${initialPage}/${initialSection}` : `#staff/${initialPage}`}`)
    setOverview(null)
    setPageData(null)
    setAuthNotice('')
  }

  function openPublicStorefront() {
    window.location.hash = '#/home'
    setStorefrontOpen(true)
  }

  function openStaffSignIn() {
    window.location.hash = '#staff'
    setStorefrontOpen(false)
  }

  function clearSession() {
    setApiAccessToken(null)
    setToken(null)
    setUser(null)
    setOverview(null)
    setPageData(null)
    window.location.hash = '#/home'
    setStorefrontOpen(true)
  }

  async function signOut() {
    try {
      await apiRequest('/auth/logout', { method: 'POST' })
    } catch {
      if (token) setAuthNotice('Signed out locally. The server could not confirm token revocation.')
    } finally {
      clearSession()
    }
  }

  if (!token || !user) {
    return storefrontOpen
      ? <StorefrontPage user={null} onLogin={signIn} onStaffLogin={openStaffSignIn} onExit={() => setStorefrontOpen(false)} />
      : <LoginScreen onLogin={signIn} initialNotice={authNotice} onOpenStore={openPublicStorefront} />
  }

  if (user.password_reset_required) {
    return (
      <ChangePasswordScreen
        onComplete={() => {
          setAuthNotice('Password updated. Please sign in with your new password.')
          clearSession()
        }}
        onLogout={() => void signOut()}
      />
    )
  }

  if (!user.email_verified_at) {
    return <VerificationScreen email={user.email} onSignOut={() => void signOut()} />
  }
  if (user.customer_id) {
    return storefrontOpen
      ? <StorefrontPage user={user} onLogin={signIn} onStaffLogin={openStaffSignIn} onExit={() => setStorefrontOpen(false)} />
      : <CustomerPortal user={user} onSignOut={() => void signOut()} onShop={() => setStorefrontOpen(true)} />
  }
  const activeBranchName = selectedBranch === 'all'
    ? 'All branches'
    : overview?.branches.find((branch) => String(branch.id) === selectedBranch)?.name ?? 'Selected branch'
  const activeSections = visibleStaffSections(page, user.permissions)
  const activeSection = activeSections.find((section) => section.key === pageSection)

  return (
    <div className="app-shell">
      <a className="skip-link" href="#main-content">Skip to content</a>
      <aside className={`sidebar ${mobileNavOpen ? 'sidebar-open' : ''}`}>
        <a
          className="brand sidebar-brand"
          href="#staff/overview"
          onClick={(event) => {
            event.preventDefault()
            navigateToPage(visibleNavigation[0]?.key ?? 'overview')
          }}
        >
          <span className="brand-mark" aria-hidden="true">o</span>
          <span>oshel<span className="brand-period">.</span></span>
        </a>
        <div className="workspace-picker">
          <span className="workspace-avatar">{initials(overview?.organization?.name ?? 'Oshel')}</span>
          <span className="workspace-copy">
            <strong>{overview?.organization?.name ?? 'Clinic workspace'}</strong>
            <small>{user.roles[0] ?? 'Team member'}</small>
          </span>
          <span className="workspace-chevron" aria-hidden="true">⌄</span>
        </div>
        <nav className="primary-nav" id="primary-navigation" aria-label="Main navigation">
          {visibleNavigationGroups.map((group) => (
            <section className="nav-group" key={group.key}>
              <button
                className="nav-group-toggle"
                type="button"
                aria-expanded={expandedNavigationGroups.includes(group.key)}
                aria-controls={`nav-group-${group.key}`}
                onClick={() => setExpandedNavigationGroups((expanded) =>
                  expanded.includes(group.key)
                    ? expanded.filter((key) => key !== group.key)
                    : [...expanded, group.key],
                )}
              >
                <span>{group.label}</span>
                <span className="nav-group-count">{group.items.length}</span>
                <span className="nav-group-chevron" aria-hidden="true">
                  {expandedNavigationGroups.includes(group.key) ? '−' : '+'}
                </span>
              </button>
              {expandedNavigationGroups.includes(group.key) && (
                <div className="nav-group-items" id={`nav-group-${group.key}`}>
                  {group.items.map((item) => {
                    const sections = visibleStaffSections(item.key, user.permissions)
                    const isActive = page === item.key
                    const isExpanded = sections.length > 0 && (expandedModules.includes(item.key) || isActive)
                    return (
                      <div className={`nav-module ${isActive ? 'nav-module-active' : ''}`} key={item.key}>
                        <div className="nav-module-row">
                          <button
                            className={`nav-link ${isActive ? 'nav-link-active' : ''}`}
                            type="button"
                            onClick={() => {
                              navigateToPage(item.key)
                              setMobileNavOpen(false)
                            }}
                            aria-current={isActive && !pageSection ? 'page' : undefined}
                          >
                            <span className="nav-icon" aria-hidden="true">{item.icon}</span>
                            {item.label}
                            {item.key === 'audit' && <span className="nav-new">LOG</span>}
                          </button>
                          {sections.length > 0 && (
                            <button
                              className="nav-module-toggle"
                              type="button"
                              aria-expanded={isExpanded}
                              aria-controls={`nav-module-${item.key}`}
                              aria-label={`${isExpanded ? 'Hide' : 'Show'} ${item.label} pages`}
                              onClick={() => toggleStaffModule(item.key)}
                            >
                              <span aria-hidden="true">{isExpanded ? '−' : '+'}</span>
                            </button>
                          )}
                        </div>
                        {isExpanded && (
                          <div className="nav-sublinks" id={`nav-module-${item.key}`}>
                            {sections.map((section) => {
                              const sectionActive = isActive && pageSection === section.key
                              return (
                                <button
                                  className={`nav-sublink ${sectionActive ? 'nav-sublink-active' : ''}`}
                                  key={section.key}
                                  type="button"
                                  onClick={() => {
                                    navigateToPage(item.key, section.key)
                                    setMobileNavOpen(false)
                                  }}
                                  aria-current={sectionActive ? 'page' : undefined}
                                >
                                  <span className="nav-sublink-dot" aria-hidden="true" />
                                  {section.label}
                                </button>
                              )
                            })}
                          </div>
                        )}
                      </div>
                    )
                  })}
                </div>
              )}
            </section>
          ))}
          {visibleNavigation.length === 0 && (
            <p className="nav-empty">No modules are assigned to your account yet.</p>
          )}
        </nav>
        {canAddStaff && (
          <button className="nav-link sidebar-add-staff" type="button" onClick={openAddStaff}>
            <span className="nav-icon" aria-hidden="true">＋</span>
            Add staff
          </button>
        )}
        <div className="sidebar-bottom">
          <div className="support-card">
            <span className="support-sparkle">✳</span>
            <strong>Here for your team</strong>
            <span>Thoughtful care starts with a supported team.</span>
          </div>
          <button className="profile-button" type="button" onClick={() => setPasswordOpen(true)}>
            <span className="profile-avatar">{initials(user.name)}</span>
            <span className="profile-copy"><strong>{user.name}</strong><small>{roleDescriptions[user.roles[0]] ?? user.roles[0] ?? 'Team member'}</small></span>
            <span className="profile-menu" aria-hidden="true">•••</span>
          </button>
        </div>
      </aside>

      {mobileNavOpen && (
        <button
          className="mobile-backdrop"
          type="button"
          aria-label="Close navigation"
          onClick={() => setMobileNavOpen(false)}
        />
      )}

      <main className="main-area" id="main-content">
        <header className="topbar">
          <button
            className="mobile-menu button-icon-only"
            type="button"
            aria-label="Open navigation"
            aria-controls="primary-navigation"
            aria-expanded={mobileNavOpen}
            onClick={() => setMobileNavOpen(true)}
          >
            <span aria-hidden="true">☰</span>
          </button>
          <div className="breadcrumb">
            <span>Workspace</span>
            <span className="breadcrumb-divider">/</span>
            <strong>{pageTitle(page)}</strong>
            {activeSection && (
              <>
                <span className="breadcrumb-divider">/</span>
                <span>{activeSection.label}</span>
              </>
            )}
          </div>
          <div className="topbar-actions">
            {hasGlobalBranchAccess && (
              <label className="branch-select">
                <span aria-hidden="true" className="branch-select-icon">⌖</span>
                <span className="sr-only">Select branch scope</span>
                <select
                  value={selectedBranch}
                  onChange={(event) => setSelectedBranch(event.target.value)}
                >
                  <option value="all">All branches</option>
                  {(overview?.branches ?? []).map((branch) => (
                    <option key={branch.id} value={branch.id}>{branch.name}</option>
                  ))}
                </select>
                <span aria-hidden="true">⌄</span>
              </label>
            )}
            <NotificationBell
              unreadCount={inboxUnread}
              onUnreadCount={setInboxUnread}
              onOpenInbox={() => navigateToPage('inbox')}
            />
            <button className="topbar-avatar" type="button" onClick={() => setPasswordOpen(true)} aria-label={`Account settings for ${user.name}`}>
              {initials(user.name)}
            </button>
          </div>
        </header>

        <div className="page-content">
          {error && (
            <div className="notice error page-notice" role="alert">
              <span>{error}</span>
              <button className="text-button" type="button" onClick={() => void loadPage()}>Try again</button>
            </div>
          )}
          {activeSections.length > 0 && (
            <nav className="module-tabs" aria-label={`${pageTitle(page)} pages`}>
              {activeSections.map((section) => {
                const isCurrent = pageSection === section.key
                return (
                  <button
                    key={section.key}
                    className={`module-tab ${isCurrent ? 'module-tab-active' : ''}`}
                    type="button"
                    aria-current={isCurrent ? 'page' : undefined}
                    onClick={() => navigateToPage(page, section.key)}
                  >
                    {section.label}
                  </button>
                )
              })}
            </nav>
          )}
          {loading && !pageData ? (
            <LoadingState />
          ) : visibleNavigation.length === 0 ? (
            <NoModulesPage role={user.roles[0]} />
          ) : page === 'overview' ? (
            <OverviewPage
              user={user}
              overview={overview}
              branchScope={selectedBranch}
              onNavigate={navigateToPage}
              onAddStaff={openAddStaff}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'inbox' ? (
            <InboxPage
              loading={loading}
              onRefresh={() => void loadPage()}
              onUnreadChange={setInboxUnread}
            />
          ) : page === 'analytics' ? (
            <AnalyticsPage
              data={pageData as AnalyticsDashboard | null}
              user={user}
              branchScope={selectedBranch}
              loading={loading}
              onReportLoad={(report) => setPageData(report)}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'branches' ? (
            <BranchesPage
              data={pageData as Branch[] | null}
              loading={loading}
              user={user}
              branchScope={selectedBranch}
              activeBranchName={activeBranchName}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'departments' ? (
            <DepartmentsPage
              data={pageData as Department[] | null}
              loading={loading}
              user={user}
              branches={overview?.branches ?? []}
              branchScope={selectedBranch}
              activeBranchName={activeBranchName}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'users' ? (
            <TeamMembersPage
              data={pageData as Paginated<ApiUser> | null}
              loading={loading}
              user={user}
              branches={overview?.branches ?? []}
              page={staffPage}
              onPage={setStaffPage}
              createRequest={staffCreateRequest}
              handledCreateRequest={handledStaffCreateRequest}
              onCreateRequestHandled={() => setHandledStaffCreateRequest(staffCreateRequest)}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'roles' ? (
            <RolesPage
              data={pageData as RoleList | null}
              loading={loading}
              onNavigate={navigateToPage}
            />
          ) : page === 'customers' ? (
            <CustomersPage
              data={pageData as Paginated<CustomerRecord> | null}
              loading={loading}
              user={user}
              branches={overview?.branches ?? []}
              page={staffPage}
              onPage={setStaffPage}
              onRefresh={() => void loadPage()}
              onNavigate={navigateToPage}
            />
          ) : page === 'services' ? (
            <ServiceCataloguePage
              data={pageData as ServiceRecord[] | null}
              loading={loading}
              user={user}
              branches={overview?.branches ?? []}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'appointments' ? (
            <AppointmentsPage
              data={pageData as Paginated<AppointmentRecord> | null}
              loading={loading}
              user={user}
              branches={overview?.branches ?? []}
              section={pageSection}
              page={staffPage}
              onPage={setStaffPage}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'clinical' ? (
            <ClinicalCarePage
              data={pageData as Paginated<ClinicalEncounterRecord> | null}
              loading={loading}
              user={user}
              branches={overview?.branches ?? []}
              page={staffPage}
              onPage={setStaffPage}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'hr' ? (
            <HrWorkspacePage
              data={pageData as {
                employees: Paginated<EmployeeRecord>
                shifts: Paginated<Record<string, unknown>>
                attendance: Paginated<Record<string, unknown>>
                leaveRequests: Paginated<Record<string, unknown>>
              } | null}
              user={user}
              branches={overview?.branches ?? []}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'payroll' ? (
            <PayrollWorkspacePage
              data={pageData as {
                runs: Paginated<PayrollRunRecord>
                branches: Branch[]
                employees: Paginated<EmployeeRecord>
                components: Record<string, unknown>[]
                commissions: Paginated<Record<string, unknown>>
              } | null}
              user={user}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'inventory' ? (
            <InventoryWorkspacePage
              data={pageData as InventoryOverview | null}
              user={user}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'procurement' ? (
            <ProcurementWorkspacePage
              data={pageData as { procurement: ProcurementOverview; inventory: InventoryOverview | null } | null}
              user={user}
              branches={overview?.branches ?? []}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'pos' ? (
            <PosWorkspacePage
              data={pageData as PosOverview | null}
              user={user}
              section={pageSection}
            />
          ) : page === 'pos-management' ? (
            <PosManagementPage
              user={user}
              section={pageSection}
            />
          ) : page === 'ecommerce' ? (
            <EcommerceOrdersPage
              orders={pageData as EcommerceOrder[] | null}
              user={user}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'payments' ? (
            <PaymentsReconciliationPage
              records={(pageData as { data: PaymentReconciliationRow[] } | null)?.data ?? null}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'accounting' ? (
            <AccountingWorkspacePage
              data={pageData as AccountingWorkspace | null}
              user={user}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'crm' ? (
            <CrmWorkspacePage
              data={pageData as CrmWorkspace | null}
              user={user}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'website' ? (
            <WebsiteContentPage
              pages={(pageData as Paginated<WebsitePage> | null)?.data ?? []}
              user={user}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'growth' ? (
            <WebsiteGrowthPage
              data={pageData as WebsiteGrowthData | null}
              user={user}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'marketing' ? (
            <MarketingCenterPage
              data={pageData as MarketingCenterData | null}
              user={user}
              branches={overview?.branches ?? []}
              branchScope={selectedBranch}
              section={pageSection}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'organization' ? (
            <ClinicProfilePage
              data={pageData as OrganizationRecord | null}
              user={user}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'email' ? (
            <EmailSettingsPage
              data={pageData as EmailSettingsRecord | null}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'notifications' ? (
            <NotificationSettingsPage
              data={pageData as NotificationSettingsRecord | null}
              onRefresh={() => void loadPage()}
            />
          ) : page === 'account' ? (
            <AccountSecurityPage
              user={user}
              sessions={pageData as AuthSessionRecord[] | null}
              onSignOut={() => {
                setAuthNotice('Password updated. Please sign in with your new password.')
                clearSession()
              }}
              onRefresh={() => void loadPage()}
            />
          ) : (
            <ModulePage
              page={page}
              data={pageData}
              loading={loading}
              branchScope={selectedBranch}
              activeBranchName={activeBranchName}
              onRefresh={() => void loadPage()}
            />
          )}
        </div>
      </main>

      {passwordOpen && (
        <AccountDialog
          user={user}
          onClose={() => setPasswordOpen(false)}
          onLogout={() => void signOut()}
          onPasswordChanged={() => {
            setPasswordOpen(false)
            setAuthNotice('Password updated. Please sign in again.')
            clearSession()
          }}
        />
      )}
      {toast && <div className="toast" role="status">{toast}</div>}
    </div>
  )
}

function visibleNavigationForUser(permissions: string[]): NavigationItem[] {
  return filterNavigationByPermissions(navigation, permissions) as NavigationItem[]
}

function navigationGroupKey(page: PageKey): string | undefined {
  return navigationGroups.find((group) => group.pages.includes(page))?.key
}

function NoModulesPage({ role }: { role?: string }) {
  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">YOUR WORKSPACE</span>
          <h1>{roleDescriptions[role ?? ''] ?? 'Clinic workspace'}</h1>
          <p className="muted">{role ?? 'Team member'}</p>
        </div>
      </div>
      <section className="panel">
        <EmptyState
          title="No modules assigned yet"
          message="There are no available modules assigned to this account. Contact your administrator if you expected to see a workspace here."
        />
      </section>
    </div>
  )
}

function BranchesPage({
  data,
  loading,
  user,
  branchScope,
  activeBranchName,
  onRefresh,
}: {
  data: Branch[] | null
  loading: boolean
  user: AuthUser
  branchScope: string
  activeBranchName: string
  onRefresh: () => void
}) {
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  const canManage = user.permissions.includes('branch.manage')
  const branches = (data ?? []).filter((branch) =>
    (branchScope === 'all' || String(branch.id) === branchScope)
    && includesSearch([branch.name, branch.code, branch.city], search),
  )

  async function remove(branch: Branch) {
    if (!window.confirm(`Delete ${branch.name}? Branches with departments or team members cannot be deleted.`)) return
    setBusyId(branch.id)
    setNotice('')
    try {
      await apiRequest(`/branches/${branch.id}`, { method: 'DELETE' })
      setNotice(`${branch.name} was deleted.`)
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">ORGANIZATION</span><h1>Branches</h1><p className="muted">Create and maintain the clinic locations in your organization.</p></div>
        {canManage && <button className="button button-primary" type="button" onClick={() => setEditing('new')}>＋ New branch</button>}
      </div>
      {notice && <div className="notice info" role="status">{notice}</div>}
      <section className="panel table-panel">
        <div className="table-toolbar">
          <div className="table-scope"><span className="scope-dot" />{branchScope === 'all' ? 'All permitted branches' : activeBranchName}</div>
          <label className="search-field">
            <span aria-hidden="true">⌕</span>
            <span className="sr-only">Search branches</span>
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search branches…" />
          </label>
        </div>
        {loading && !data ? <LoadingState compact /> : branches.length ? (
          <div className="table-scroll"><table><thead><tr><th>BRANCH</th><th>CODE</th><th>LOCATION</th><th>DEPARTMENTS</th><th>STATUS</th>{canManage && <th>ACTIONS</th>}</tr></thead>
            <tbody>{branches.map((branch) => <tr key={branch.id}>
              <td><span className="table-entity"><span className="table-icon sage">⌖</span><strong>{branch.name}</strong></span></td>
              <td><span className="code-chip">{branch.code}</span></td>
              <td>{branch.city || '—'}</td>
              <td>{branch.departments_count ?? 0}</td>
              <td><StatusPill active={branch.is_active} /></td>
              {canManage && <td><div className="row-actions">
                <button className="text-button" type="button" onClick={() => setEditing(branch)}>Edit</button>
                <button className="text-button danger-text" type="button" disabled={busyId === branch.id} onClick={() => void remove(branch)}>{busyId === branch.id ? 'Deleting…' : 'Delete'}</button>
              </div></td>}
            </tr>)}</tbody></table></div>
        ) : <EmptyState title="No branches found" message={search ? 'Try another search term.' : 'Create a branch to start organizing your clinic locations.'} />}
      </section>
      {editing && (
        <BranchFormDialog
          user={user}
          branch={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(saved) => {
            setEditing(null)
            setNotice(saved ? `${saved.name} was saved.` : 'Branch saved.')
            onRefresh()
          }}
        />
      )}
    </div>
  )
}

function BranchFormDialog({
  user,
  branch,
  onClose,
  onSaved,
}: {
  user: AuthUser
  branch: Branch | null
  onClose: () => void
  onSaved: (branch: Branch | null) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!user.organization_id) {
      setError('Your account is not linked to an organization.')
      return
    }
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    const payload = {
      organization_id: user.organization_id,
      name: String(form.get('name') ?? '').trim(),
      code: String(form.get('code') ?? '').trim().toUpperCase(),
      city: String(form.get('city') ?? '').trim() || null,
      country: String(form.get('country') ?? '').trim() || null,
      timezone: String(form.get('timezone') ?? '') || null,
      email: String(form.get('email') ?? '').trim() || null,
      phone: String(form.get('phone') ?? '').trim() || null,
      address: String(form.get('address') ?? '').trim() || null,
      is_active: form.get('is_active') === 'on',
    }
    try {
      const saved = await apiRequest<Branch>(branch ? `/branches/${branch.id}` : '/branches', {
        method: branch ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })
      onSaved(saved)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="branch-form-title">
        <div className="dialog-heading"><div><span className="eyebrow">BRANCH DETAILS</span><h2 id="branch-form-title">{branch ? `Edit ${branch.name}` : 'New branch'}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <div className="customer-form-grid">
            <label>Branch name<input name="name" required maxLength={255} defaultValue={branch?.name ?? ''} /></label>
            <label>Branch code<input name="code" required maxLength={50} defaultValue={branch?.code ?? ''} placeholder="e.g. NRB-CBD" /></label>
          </div>
          <div className="customer-form-grid">
            <label>City<input name="city" maxLength={120} defaultValue={branch?.city ?? ''} /></label>
            <label>Country<input name="country" maxLength={120} defaultValue={branch?.country ?? ''} /></label>
          </div>
          <div className="customer-form-grid">
            <label>Email<input name="email" type="email" maxLength={255} defaultValue={branch?.email ?? ''} /></label>
            <label>Phone<input name="phone" type="tel" maxLength={40} defaultValue={branch?.phone ?? ''} /></label>
          </div>
          <label>Timezone
            <select name="timezone" defaultValue={branch?.timezone ?? 'Africa/Nairobi'}>
              <option value="Africa/Nairobi">Africa/Nairobi</option>
              <option value="Africa/Kampala">Africa/Kampala</option>
              <option value="Africa/Dar_es_Salaam">Africa/Dar_es_Salaam</option>
              <option value="Africa/Kigali">Africa/Kigali</option>
              <option value="Africa/Addis_Ababa">Africa/Addis_Ababa</option>
              <option value="UTC">UTC</option>
            </select>
          </label>
          <label>Address<textarea name="address" rows={3} maxLength={2000} defaultValue={branch?.address ?? ''} /></label>
          <label className="customer-check"><input name="is_active" type="checkbox" defaultChecked={branch?.is_active ?? true} /> Branch is active</label>
          <div className="dialog-actions">
            <button className="button button-secondary" type="button" onClick={onClose}>Cancel</button>
            <button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save branch'}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function DepartmentsPage({
  data,
  loading,
  user,
  branches,
  branchScope,
  activeBranchName,
  onRefresh,
}: {
  data: Department[] | null
  loading: boolean
  user: AuthUser
  branches: Branch[]
  branchScope: string
  activeBranchName: string
  onRefresh: () => void
}) {
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Department | 'new' | null>(null)
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  const canManage = user.permissions.includes('department.manage')
  const departments = (data ?? []).filter((department) =>
    (branchScope === 'all' || String(department.branch_id) === branchScope)
    && includesSearch([department.name, department.code, department.branch?.name], search),
  )

  async function remove(department: Department) {
    if (!window.confirm(`Delete ${department.name}?`)) return
    setBusyId(department.id)
    setNotice('')
    try {
      await apiRequest(`/departments/${department.id}`, { method: 'DELETE' })
      setNotice(`${department.name} was deleted.`)
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">ORGANIZATION</span><h1>Departments</h1><p className="muted">Create and maintain the teams and services across your branches.</p></div>
        {canManage && <button className="button button-primary" type="button" onClick={() => setEditing('new')}>＋ New department</button>}
      </div>
      {notice && <div className="notice info" role="status">{notice}</div>}
      <section className="panel table-panel">
        <div className="table-toolbar">
          <div className="table-scope"><span className="scope-dot" />{branchScope === 'all' ? 'All permitted branches' : activeBranchName}</div>
          <label className="search-field">
            <span aria-hidden="true">⌕</span>
            <span className="sr-only">Search departments</span>
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search departments…" />
          </label>
        </div>
        {loading && !data ? <LoadingState compact /> : departments.length ? (
          <div className="table-scroll"><table><thead><tr><th>DEPARTMENT</th><th>CODE</th><th>BRANCH</th><th>STATUS</th>{canManage && <th>ACTIONS</th>}</tr></thead>
            <tbody>{departments.map((department, index) => <tr key={department.id}>
              <td><span className="table-entity"><span className={`table-icon department-tone-${index % 4}`}>✳</span><strong>{department.name}</strong></span></td>
              <td><span className="code-chip">{department.code}</span></td>
              <td>{department.branch?.name ?? 'Organization-wide'}</td>
              <td><StatusPill active={department.is_active} /></td>
              {canManage && <td><div className="row-actions">
                <button className="text-button" type="button" onClick={() => setEditing(department)}>Edit</button>
                <button className="text-button danger-text" type="button" disabled={busyId === department.id} onClick={() => void remove(department)}>{busyId === department.id ? 'Deleting…' : 'Delete'}</button>
              </div></td>}
            </tr>)}</tbody></table></div>
        ) : <EmptyState title="No departments found" message={search ? 'Try another search term.' : 'Create a department to group the teams working across your branches.'} />}
      </section>
      {editing && (
        <DepartmentFormDialog
          user={user}
          branches={branches}
          department={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(saved) => {
            setEditing(null)
            setNotice(saved ? `${saved.name} was saved.` : 'Department saved.')
            onRefresh()
          }}
        />
      )}
    </div>
  )
}

function DepartmentFormDialog({
  user,
  branches,
  department,
  onClose,
  onSaved,
}: {
  user: AuthUser
  branches: Branch[]
  department: Department | null
  onClose: () => void
  onSaved: (department: Department | null) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const canChooseBranch = user.permissions.includes('branch.view_all')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!user.organization_id) {
      setError('Your account is not linked to an organization.')
      return
    }
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    const branchId = canChooseBranch ? String(form.get('branch_id') ?? '') : String(user.branch_id ?? '')
    const payload = {
      organization_id: user.organization_id,
      branch_id: branchId ? Number(branchId) : null,
      name: String(form.get('name') ?? '').trim(),
      code: String(form.get('code') ?? '').trim().toUpperCase(),
      description: String(form.get('description') ?? '').trim() || null,
      is_active: form.get('is_active') === 'on',
    }
    try {
      const saved = await apiRequest<Department>(department ? `/departments/${department.id}` : '/departments', {
        method: department ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })
      onSaved(saved)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="department-form-title">
        <div className="dialog-heading"><div><span className="eyebrow">DEPARTMENT DETAILS</span><h2 id="department-form-title">{department ? `Edit ${department.name}` : 'New department'}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <div className="customer-form-grid">
            <label>Department name<input name="name" required maxLength={255} defaultValue={department?.name ?? ''} /></label>
            <label>Department code<input name="code" required maxLength={80} defaultValue={department?.code ?? ''} placeholder="e.g. SKIN-CARE" /></label>
          </div>
          {canChooseBranch ? (
            <label>Branch
              <select name="branch_id" defaultValue={department?.branch_id ?? ''}>
                <option value="">Organization-wide</option>
                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
              </select>
            </label>
          ) : (
            <label>Branch
              <select name="branch_id" defaultValue={String(user.branch_id ?? '')} disabled>
                {branches.filter((branch) => branch.id === user.branch_id).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
              </select>
            </label>
          )}
          <label>Description<textarea name="description" rows={3} defaultValue={department?.description ?? ''} /></label>
          <label className="customer-check"><input name="is_active" type="checkbox" defaultChecked={department?.is_active ?? true} /> Department is active</label>
          <div className="dialog-actions">
            <button className="button button-secondary" type="button" onClick={onClose}>Cancel</button>
            <button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save department'}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function TeamMembersPage({
  data,
  loading,
  user,
  branches,
  page,
  onPage,
  createRequest,
  handledCreateRequest,
  onCreateRequestHandled,
  onRefresh,
}: {
  data: Paginated<ApiUser> | null
  loading: boolean
  user: AuthUser
  branches: Branch[]
  page: number
  onPage: (page: number) => void
  createRequest: number
  handledCreateRequest: number
  onCreateRequestHandled: () => void
  onRefresh: () => void
}) {
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<ApiUser | 'new' | null>(null)
  const [notice, setNotice] = useState('')
  const [roleOptions, setRoleOptions] = useState<string[]>([])
  const canManage = user.permissions.includes('users.manage') && user.permissions.includes('roles.assign')
  const members = (data?.data ?? []).filter((member) => includesSearch([
    member.name, member.email, ...member.roles.map((role) => role.name),
  ], search))

  useEffect(() => {
    if (!canManage || createRequest <= handledCreateRequest) return
    setEditing('new')
    onCreateRequestHandled()
  }, [createRequest, handledCreateRequest, canManage, onCreateRequestHandled])

  useEffect(() => {
    if (!canManage) return
    let active = true
    apiRequest<RoleList>('/roles?assignable=1')
      .then((result) => {
        if (active) setRoleOptions(result.roles.map((role) => role.name))
      })
      .catch(() => {
        if (active) setRoleOptions([])
      })
    return () => { active = false }
  }, [canManage])

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">ORGANIZATION</span><h1>Staff</h1><p className="muted">Add staff, assign one of the nine approved roles, and keep branch access up to date.</p></div>
        {canManage && <button className="button button-primary" type="button" onClick={() => setEditing('new')}>＋ Add staff</button>}
      </div>
      {notice && <div className="notice info" role="status">{notice}</div>}
      <section className="panel table-panel">
        <div className="table-toolbar">
          <div className="table-scope"><span className="scope-dot" />{data?.total ?? 0} staff</div>
          <label className="search-field">
            <span aria-hidden="true">⌕</span>
            <span className="sr-only">Search staff</span>
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search staff…" />
          </label>
        </div>
        {loading && !data ? <LoadingState compact /> : members.length ? (
          <div className="table-scroll"><table><thead><tr><th>TEAM MEMBER</th><th>ROLE</th><th>BRANCH ASSIGNMENTS</th><th>STATUS</th>{canManage && <th>ACTIONS</th>}</tr></thead>
            <tbody>{members.map((member) => <tr key={member.id}>
              <td><span className="table-entity"><span className="profile-avatar small">{initials(member.name)}</span><span><strong>{member.name}</strong><small className="table-subtext">{member.email}</small></span></span></td>
              <td>{member.roles.map((role) => <span className="role-chip" key={role.name}>{role.name}</span>)}</td>
              <td>{member.branch_assignments.length ? member.branch_assignments.map((branch) => branch.name).join(', ') : '—'}</td>
              <td><StatusPill active={member.is_active} /></td>
              {canManage && <td><div className="row-actions"><button className="text-button" type="button" onClick={() => setEditing(member)}>Edit access</button></div></td>}
            </tr>)}</tbody></table></div>
        ) : <EmptyState title="No staff found" message={search ? 'Try another search term.' : 'Add a staff member to give them access to the workspace.'} />}
        {data && !search && <Pagination page={data.current_page ?? page} totalPages={data.last_page ?? 1} total={data.total} pageSize={data.per_page ?? 50} onPage={onPage} label="Staff pagination" />}
      </section>
      {editing && (
        <TeamMemberFormDialog
          member={editing === 'new' ? null : editing}
          roleOptions={roleOptions}
          branches={branches}
          onClose={() => setEditing(null)}
          onSaved={(saved) => {
            setEditing(null)
            setNotice(saved ? `${saved.name} was saved.` : 'Team member saved.')
            onRefresh()
          }}
        />
      )}
    </div>
  )
}

function TeamMemberFormDialog({
  member,
  roleOptions,
  branches,
  onClose,
  onSaved,
}: {
  member: ApiUser | null
  roleOptions: string[]
  branches: Branch[]
  onClose: () => void
  onSaved: (member: ApiUser | null) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [selectedRoles, setSelectedRoles] = useState<string[]>(member?.roles.map((role) => role.name) ?? [])
  const [selectedBranches, setSelectedBranches] = useState<number[]>(
    member?.branch_assignments.map((assignment) => assignment.pivot?.branch_id ?? assignment.id) ?? [],
  )
  const today = new Date().toISOString().slice(0, 10)

  function toggleRole(role: string) {
    setSelectedRoles((current) => current.includes(role)
      ? current.filter((item) => item !== role)
      : [...current, role])
  }

  function toggleBranch(branchId: number) {
    setSelectedBranches((current) => current.includes(branchId)
      ? current.filter((item) => item !== branchId)
      : [...current, branchId])
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedRoles.length) {
      setError('Select at least one role.')
      return
    }
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    const existingAssignments = new Map(
      (member?.branch_assignments ?? []).map((assignment) => [assignment.pivot?.branch_id ?? assignment.id, assignment.pivot]),
    )
    const payload = {
      name: String(form.get('name') ?? '').trim(),
      email: String(form.get('email') ?? '').trim(),
      roles: selectedRoles,
      branch_assignments: selectedBranches.map((branchId, index) => ({
        branch_id: branchId,
        effective_from: existingAssignments.get(branchId)?.effective_from ?? today,
        effective_until: existingAssignments.get(branchId)?.effective_until ?? null,
        is_primary: index === 0,
      })),
      ...(member ? { is_active: form.get('is_active') === 'on' } : {}),
    }
    try {
      const saved = await apiRequest<ApiUser>(member ? `/users/${member.id}` : '/users', {
        method: member ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })
      onSaved(saved)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="team-member-form-title">
        <div className="dialog-heading"><div><span className="eyebrow">TEAM ACCESS</span><h2 id="team-member-form-title">{member ? `Edit ${member.name}` : 'Invite team member'}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {!member && <div className="notice info" role="status">The new team member will receive an email verification link and a password reset link so they can activate their account.</div>}
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <div className="customer-form-grid">
            <label>Full name<input name="name" required maxLength={255} defaultValue={member?.name ?? ''} /></label>
            <label>Email<input name="email" type="email" required maxLength={255} defaultValue={member?.email ?? ''} /></label>
          </div>
          <fieldset className="customer-patient-fields">
            <legend>Roles</legend>
            <div className="checkbox-grid">
              {roleOptions.length ? roleOptions.map((role) => (
                <label key={role}><input type="checkbox" checked={selectedRoles.includes(role)} onChange={() => toggleRole(role)} /> {role}</label>
              )) : <span className="muted">No roles are available to assign.</span>}
            </div>
          </fieldset>
          <fieldset className="customer-patient-fields">
            <legend>Branch assignments</legend>
            <div className="checkbox-grid">
              {branches.map((branch) => (
                <label key={branch.id}><input type="checkbox" checked={selectedBranches.includes(branch.id)} onChange={() => toggleBranch(branch.id)} /> {branch.name}</label>
              ))}
            </div>
            <p className="form-hint">The first selected branch becomes the primary branch.</p>
          </fieldset>
          {member && <label className="customer-check"><input name="is_active" type="checkbox" defaultChecked={member.is_active} /> Account is active</label>}
          <div className="dialog-actions">
            <button className="button button-secondary" type="button" onClick={onClose}>Cancel</button>
            <button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : (member ? 'Save access' : 'Send invitation')}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function RolesPage({
  data,
  loading,
  onNavigate,
}: {
  data: RoleList | null
  loading: boolean
  onNavigate: (page: PageKey) => void
}) {
  const [search, setSearch] = useState('')
  const roles = (data?.roles ?? []).filter((role) => includesSearch([role.name, ...role.permissions.map((permission) => permission.name)], search))

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">ORGANIZATION</span><h1>Roles &amp; access</h1><p className="muted">Review the permission sets assigned across your team.</p></div>
        <button className="button button-secondary" type="button" onClick={() => onNavigate('users')}>Manage team access <span aria-hidden="true">→</span></button>
      </div>
      <section className="panel">
        <div className="table-toolbar">
          <div className="table-scope"><span className="scope-dot" />{data?.roles.length ?? 0} roles · {data?.permissions.length ?? 0} permissions</div>
          <label className="search-field">
            <span aria-hidden="true">⌕</span>
            <span className="sr-only">Search roles and permissions</span>
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search roles or permissions…" />
          </label>
        </div>
        {loading && !data ? <LoadingState compact /> : roles.length ? (
          <div className="role-grid">{roles.map((role) => (
            <article className="role-card" key={role.id}>
              <div className="role-card-heading"><span className="role-symbol">◇</span><span><h3>{role.name}</h3><small>{role.permissions.length} foundation permissions</small></span></div>
              <div className="permission-list">{role.permissions.length
                ? role.permissions.map((permission) => <span className="permission-chip" key={permission.id}>{permission.name}</span>)
                : <span className="muted">No foundation permissions</span>}</div>
            </article>
          ))}</div>
        ) : <EmptyState title="No roles found" message={search ? 'Try another search term.' : 'Roles will appear here once they are configured.'} />}
      </section>
    </div>
  )
}

function EcommerceOrdersPage({
  orders,
  user,
  onRefresh,
}: {
  orders: EcommerceOrder[] | null
  user: AuthUser
  onRefresh: () => void
}) {
  const [busyOrderId, setBusyOrderId] = useState<number | null>(null)
  const [notice, setNotice] = useState('')
  const ordersPage = usePaginatedList(orders ?? [], 8)

  async function updateOrder(order: EcommerceOrder, action: 'cancel' | 'approve' | 'reject') {
    const confirmed = window.confirm(action === 'cancel'
      ? `Cancel unpaid order ${order.order_number}?`
      : `${action === 'approve' ? 'Approve' : 'Reject'} the return request for ${order.order_number}?`)
    if (!confirmed) return
    setBusyOrderId(order.id)
    setNotice('')
    try {
      if (action === 'cancel') {
        await apiRequest(`/ecommerce/orders/${order.id}/fulfillment`, {
          method: 'PATCH',
          body: JSON.stringify({ status: 'cancelled' }),
        })
      } else {
        const result = await apiRequest<{ message?: string }>(`/ecommerce/orders/${order.id}/return-decision`, {
          method: 'POST',
          body: JSON.stringify({ decision: action }),
        })
        if (result.message) setNotice(result.message)
      }
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusyOrderId(null)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">COMMERCE</span><h1>Online orders</h1><p className="muted">Review customer orders and their payment and fulfilment state.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
      </div>
      <div className="notice info" role="status">Orders become eligible for fulfilment only after a provider confirms payment. Approved returns still require a separately completed provider refund.</div>
      {notice && <div className="notice info" role="status">{notice}</div>}
      {!user.permissions.includes('ecommerce.orders.view') && <div className="notice error" role="alert">Your account cannot view online orders.</div>}
      {orders?.length ? <div className="ecommerce-order-list">{ordersPage.pageItems.map((order) => (
        <article className="panel ecommerce-order-card" key={order.id}>
          <div className="ecommerce-order-heading"><div><span className="eyebrow">{order.order_number}</span><h2>{order.customer_name ?? 'Customer'}</h2><p className="muted">{order.branch_name ?? 'Branch'} · {new Date(order.created_at).toLocaleString()}</p></div><div className="ecommerce-order-badges"><span className="status-pill">{formatAction(order.status)}</span><span className="status-pill">{formatAction(order.payment_status)}</span></div></div>
          <div className="ecommerce-order-lines">{order.lines.map((line, index) => <div key={`${line.sku ?? line.name}-${index}`}><span>{line.quantity} × {line.name}</span><strong>{new Intl.NumberFormat('en-KE', { style: 'currency', currency: order.currency_code || 'KES' }).format(Number(line.line_total))}</strong></div>)}</div>
          <div className="ecommerce-order-footer"><span>{order.fulfillment_type === 'delivery' ? 'Delivery' : 'Collection'} · Total <strong>{new Intl.NumberFormat('en-KE', { style: 'currency', currency: order.currency_code || 'KES' }).format(Number(order.total_amount))}</strong></span><div className="ecommerce-order-actions">
            <button className="text-button" type="button" onClick={() => void apiDownload(`/documents/orders/${order.id}/invoice`, `oshel-invoice-${order.order_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>Invoice PDF</button>
            <button className="text-button" type="button" onClick={() => void apiDownload(`/documents/orders/${order.id}/receipt`, `oshel-receipt-${order.order_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>Receipt PDF</button>
            {order.status === 'awaiting_payment' && user.permissions.includes('ecommerce.orders.manage') && <button className="button button-secondary" type="button" disabled={busyOrderId === order.id} onClick={() => void updateOrder(order, 'cancel')}>Cancel unpaid order</button>}
            {order.return_status === 'requested' && user.permissions.includes('ecommerce.returns.manage') && <><button className="button button-secondary" type="button" disabled={busyOrderId === order.id} onClick={() => void updateOrder(order, 'reject')}>Reject return</button><button className="button button-primary" type="button" disabled={busyOrderId === order.id} onClick={() => void updateOrder(order, 'approve')}>Approve return request</button></>}
          </div></div>
          {order.return_status !== 'none' && <p className="muted ecommerce-return-note">Return: {formatAction(order.return_status)}{order.return_reason ? ` — ${order.return_reason}` : ''}{order.return_status === 'refund_pending' ? ' · Provider refund still requires completion and reconciliation.' : ''}</p>}
        </article>
      ))}</div> : <EmptyState title="No online orders" message="Customer orders will appear here after checkout." />}
      <Pagination page={ordersPage.currentPage} totalPages={ordersPage.totalPages} total={ordersPage.total} pageSize={ordersPage.pageSize} onPage={ordersPage.setCurrentPage} label="Online orders pagination" />
    </div>
  )
}

function PaymentsReconciliationPage({
  records,
  onRefresh,
}: {
  records: PaymentReconciliationRow[] | null
  onRefresh: () => void
}) {
  const [busyId, setBusyId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [settledAtDefault] = useState(() => new Date().toISOString().slice(0, 16))
  const recordsPage = usePaginatedList(records ?? [], 8)

  async function recordSettlement(event: FormEvent<HTMLFormElement>, record: PaymentReconciliationRow) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusyId(record.id)
    setError('')
    setNotice('')
    try {
      await apiRequest(`/payments/reconciliation/${record.id}/settlement`, {
        method: 'POST',
        body: JSON.stringify({
          statement_reference: String(form.get('statement_reference') ?? ''),
          settled_amount: String(form.get('settled_amount') ?? ''),
          fee_amount: String(form.get('fee_amount') ?? '0'),
          clearing_account_code: String(form.get('clearing_account_code') ?? ''),
          settled_at: new Date(String(form.get('settled_at') ?? '')).toISOString(),
        }),
      })
      setNotice(`Settlement for ${record.order_number ?? record.receipt_number ?? record.merchant_reference} has been recorded.`)
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">FINANCE</span><h1>Payments & reconciliation</h1><p className="muted">Match verified provider payments to settlement statements and clearing accounts.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
      </div>
      <div className="notice info" role="status">Only provider-verified payments are eligible. Cash tills are reconciled through POS shift close; refunds and general-ledger posting require separate provider and accounting workflows.</div>
      {error && <div className="notice error" role="alert">{error}</div>}
      {notice && <div className="notice success" role="status">{notice}</div>}
      {records?.length ? recordsPage.pageItems.map((record) => (
        <article className="panel ecommerce-order-card" key={record.id}>
          <div className="ecommerce-order-heading">
            <div><span className="eyebrow">{record.order_number ?? record.receipt_number ?? record.merchant_reference}</span><h2>{record.provider.toUpperCase()} · {record.branch_name}</h2><p className="muted">Created {new Date(record.created_at).toLocaleString()} · Reference {record.provider_reference ?? record.provider_transaction_id ?? record.merchant_reference}</p></div>
            <div className="ecommerce-order-badges"><span className="status-pill">{formatAction(record.status)}</span><span className="status-pill">{record.reconciliation_status ? formatAction(record.reconciliation_status) : 'Not reconciled'}</span></div>
          </div>
          <p>Expected settlement: <strong>{record.currency_code} {Number(record.amount).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong></p>
          {record.reconciliation_status ? <p className="muted">Statement {record.statement_reference ?? '—'} · settled {record.currency_code} {Number(record.settled_amount ?? 0).toFixed(2)} · fee {record.currency_code} {Number(record.fee_amount ?? 0).toFixed(2)} · account {record.clearing_account_code ?? '—'}</p>
            : record.status === 'paid' ? <form className="storefront-checkout-form" onSubmit={(event) => void recordSettlement(event, record)}>
              <label>Statement reference<input name="statement_reference" required maxLength={160} /></label>
              <label>Settled amount<input name="settled_amount" type="number" min="0" max="9999999999.99" step="0.01" required defaultValue={Number(record.amount).toFixed(2)} /></label>
              <label>Provider fee<input name="fee_amount" type="number" min="0" max="9999999999.99" step="0.01" required defaultValue="0.00" /></label>
              <label>Clearing account code<input name="clearing_account_code" required maxLength={80} defaultValue={record.clearing_account_code ?? ''} /></label>
              <label>Settlement date and time<input name="settled_at" type="datetime-local" required defaultValue={settledAtDefault} /></label>
              <button className="button button-primary" type="submit" disabled={busyId === record.id}>{busyId === record.id ? 'Recording…' : 'Record settlement'}</button>
            </form> : <p className="muted">Settlement can be recorded after the provider confirms this payment.</p>}
        </article>
      )) : <EmptyState title="No payment transactions yet" message="Provider-confirmed online payments will appear here for reconciliation." />}
      <Pagination page={recordsPage.currentPage} totalPages={recordsPage.totalPages} total={recordsPage.total} pageSize={recordsPage.pageSize} onPage={recordsPage.setCurrentPage} label="Payment reconciliation pagination" />
    </div>
  )
}

function AccountingWorkspacePage({
  data,
  user,
  section,
  onRefresh,
}: {
  data: AccountingWorkspace | null
  user: AuthUser
  section?: string
  onRefresh: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [report, setReport] = useState<AccountingReport | null>(null)
  const [reportType, setReportType] = useState('trial-balance')
  const [reportFrom, setReportFrom] = useState(() => `${new Date().getFullYear()}-01-01`)
  const [reportTo, setReportTo] = useState(() => new Date().toISOString().slice(0, 10))
  const [today] = useState(() => new Date().toISOString().slice(0, 10))
  const canManage = user.permissions.includes('accounting.manage')
  const canApprove = user.permissions.includes('accounting.approve')

  async function submit(event: FormEvent<HTMLFormElement>, path: string, method: 'POST' | 'PUT' = 'POST') {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      if (path === '/accounting/accounts') {
        await apiRequest(path, {
          method,
          body: JSON.stringify({
            code: String(form.get('code') ?? ''),
            name: String(form.get('name') ?? ''),
            account_type: String(form.get('account_type') ?? ''),
            currency_code: String(form.get('currency_code') ?? '') || null,
          }),
        })
        setNotice('Account created.')
      } else if (path === '/accounting/periods') {
        await apiRequest(path, {
          method,
          body: JSON.stringify({
            name: String(form.get('name') ?? ''),
            starts_on: String(form.get('starts_on') ?? ''),
            ends_on: String(form.get('ends_on') ?? ''),
          }),
        })
        setNotice('Accounting period opened.')
      } else if (path === '/accounting/posting-rules') {
        await apiRequest(path, {
          method,
          body: JSON.stringify({
            event_type: String(form.get('event_type') ?? ''),
            debit_account_id: Number(form.get('debit_account_id')),
            credit_account_id: Number(form.get('credit_account_id')),
            is_active: true,
          }),
        })
        setNotice('Posting rule saved; eligible pending source events were retried.')
      } else {
        const amount = String(form.get('amount') ?? '')
        await apiRequest(path, {
          method,
          body: JSON.stringify({
            journal_date: String(form.get('journal_date') ?? ''),
            currency_code: String(form.get('currency_code') ?? 'KES'),
            memo: String(form.get('memo') ?? ''),
            lines: [
              { account_id: Number(form.get('debit_account_id')), debit_amount: amount, credit_amount: '0.00' },
              { account_id: Number(form.get('credit_account_id')), debit_amount: '0.00', credit_amount: amount },
            ],
          }),
        })
        setNotice('Balanced journal draft created. A different authorized approver must post it.')
      }
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function runAction(path: string, body?: Record<string, unknown>, id?: number) {
    setBusyId(id ?? null)
    setError('')
    setNotice('')
    try {
      await apiRequest(path, {
        method: 'POST',
        ...(body ? { body: JSON.stringify(body) } : {}),
      })
      setNotice('Accounting action completed.')
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusyId(null)
    }
  }

  const loadReport = useCallback(async () => {
    setBusy(true)
    setError('')
    try {
      const params = new URLSearchParams({ report: reportType, from: reportFrom, to: reportTo })
      setReport(await apiRequest<AccountingReport>(`/accounting/reports?${params.toString()}`))
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }, [reportFrom, reportTo, reportType])

  useEffect(() => {
    void loadReport()
  }, [loadReport])

  if (!data) return <LoadingState />

  const view = section || 'accounts'
  const accountsPage = usePaginatedList(data.accounts)
  const rulesPage = usePaginatedList(data.posting_rules)
  const periodsPage = usePaginatedList(data.periods)
  const journalsPage = usePaginatedList(data.journals)

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">FINANCE · PHASE 12</span><h1>Accounting</h1><p className="muted">A double-entry ledger with controlled posting, period close, and traceable source records.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
      </div>
      <div className="notice info" role="status">Configure and approve account mappings before posting operational activity. Tax configuration, budgets, FX revaluation, and accountant sign-off remain outstanding; do not treat these reports as statutory statements.</div>
      {error && <div className="notice error" role="alert">{error}</div>}
      {notice && <div className="notice success" role="status">{notice}</div>}

      {view === 'accounts' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">CHART OF ACCOUNTS</span><h2>Accounts</h2></div></div>
        {canManage && <form className="storefront-checkout-form" onSubmit={(event) => void submit(event, '/accounting/accounts')}>
          <label>Code<input name="code" required maxLength={40} /></label>
          <label>Account name<input name="name" required maxLength={160} /></label>
          <label>Type<select name="account_type" defaultValue="asset"><option value="asset">Asset</option><option value="liability">Liability</option><option value="equity">Equity</option><option value="income">Income</option><option value="expense">Expense</option></select></label>
          <label>Currency (optional)<input name="currency_code" minLength={3} maxLength={3} placeholder="KES" /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Add account</button>
        </form>}
        {accountsPage.total ? <><div className="table-scroll"><table><thead><tr><th>CODE</th><th>ACCOUNT</th><th>TYPE</th><th>CURRENCY</th><th>STATUS</th></tr></thead><tbody>
          {accountsPage.pageItems.map((account) => <tr key={account.id}><td>{account.code}</td><td>{account.name}</td><td>{formatAction(account.account_type)}</td><td>{account.currency_code ?? 'Any'}</td><td>{account.is_active ? 'Active' : 'Inactive'}</td></tr>)}
        </tbody></table></div><Pagination page={accountsPage.currentPage} totalPages={accountsPage.totalPages} total={accountsPage.total} pageSize={accountsPage.pageSize} onPage={accountsPage.setCurrentPage} label="Chart of accounts pagination" /></> : <EmptyState title="No accounts configured" message="Create the organization’s accountant-approved chart of accounts before entering journals or configuring automation." />}
      </section>}

      {view === 'rules' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">AUTOMATION</span><h2>Source posting rules</h2></div></div>
        {canManage && data.accounts.filter((account) => account.is_active).length >= 2 && <form className="storefront-checkout-form" onSubmit={(event) => void submit(event, '/accounting/posting-rules', 'PUT')}>
          <label>Source event<select name="event_type">{data.supported_event_types.map((type) => <option value={type} key={type}>{formatAction(type)}</option>)}</select></label>
          <label>Debit account<select name="debit_account_id">{data.accounts.filter((account) => account.is_active).map((account) => <option value={account.id} key={account.id}>{account.code} · {account.name}</option>)}</select></label>
          <label>Credit account<select name="credit_account_id">{data.accounts.filter((account) => account.is_active).map((account) => <option value={account.id} key={account.id}>{account.code} · {account.name}</option>)}</select></label>
          <button className="button button-primary" type="submit" disabled={busy}>Save rule</button>
        </form>}
        {rulesPage.total ? <><div className="table-scroll"><table><thead><tr><th>EVENT</th><th>DEBIT</th><th>CREDIT</th><th>STATUS</th></tr></thead><tbody>
          {rulesPage.pageItems.map((rule) => <tr key={rule.id}><td>{formatAction(rule.event_type)}</td><td>{rule.debit_account_code} · {rule.debit_account_name}</td><td>{rule.credit_account_code} · {rule.credit_account_name}</td><td>{rule.is_active ? 'Active' : 'Inactive'}</td></tr>)}
        </tbody></table></div><Pagination page={rulesPage.currentPage} totalPages={rulesPage.totalPages} total={rulesPage.total} pageSize={rulesPage.pageSize} onPage={rulesPage.setCurrentPage} label="Posting rules pagination" /></> : <p className="muted">No automatic posting rules are configured. New source activity will remain visible in the pending queue until a mapping is saved.</p>}
      </section>}

      {view === 'periods' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">CONTROL</span><h2>Accounting periods</h2></div></div>
        {canManage && <form className="storefront-checkout-form" onSubmit={(event) => void submit(event, '/accounting/periods')}>
          <label>Period name<input name="name" required maxLength={100} placeholder="FY 2026" /></label>
          <label>Start date<input name="starts_on" type="date" required /></label>
          <label>End date<input name="ends_on" type="date" required /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Open period</button>
        </form>}
        {periodsPage.total ? <><div className="table-scroll"><table><thead><tr><th>PERIOD</th><th>DATES</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {periodsPage.pageItems.map((period) => <tr key={period.id}><td>{period.name}</td><td>{period.starts_on} — {period.ends_on}</td><td>{formatAction(period.status)}</td><td>{period.status === 'open' && user.permissions.includes('accounting.period.close') && <button className="button button-secondary" type="button" disabled={busyId === period.id} onClick={() => void runAction(`/accounting/periods/${period.id}/close`, {}, period.id)}>Close period</button>}</td></tr>)}
        </tbody></table></div><Pagination page={periodsPage.currentPage} totalPages={periodsPage.totalPages} total={periodsPage.total} pageSize={periodsPage.pageSize} onPage={periodsPage.setCurrentPage} label="Accounting periods pagination" /></> : <EmptyState title="No accounting periods" message="Open a non-overlapping period before recording or posting journals." />}
      </section>}

      {view === 'journal' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">JOURNAL ENTRY</span><h2>Create a balanced draft</h2></div></div>
        {canManage && <form className="storefront-checkout-form" onSubmit={(event) => void submit(event, '/accounting/journals')}>
          <label>Date<input name="journal_date" type="date" required defaultValue={today} /></label>
          <label>Currency<input name="currency_code" required minLength={3} maxLength={3} defaultValue="KES" /></label>
          <label>Memo<input name="memo" required minLength={3} maxLength={500} /></label>
          <label>Debit account<select name="debit_account_id" required>{data.accounts.filter((account) => account.is_active).map((account) => <option value={account.id} key={account.id}>{account.code} · {account.name}</option>)}</select></label>
          <label>Credit account<select name="credit_account_id" required>{data.accounts.filter((account) => account.is_active).map((account) => <option value={account.id} key={account.id}>{account.code} · {account.name}</option>)}</select></label>
          <label>Amount<input name="amount" type="number" min="0.01" step="0.01" required /></label>
          <button className="button button-primary" type="submit" disabled={busy || data.accounts.filter((account) => account.is_active).length < 2}>Create draft</button>
        </form>}
        {journalsPage.total ? <><div className="table-scroll"><table><thead><tr><th>NUMBER / DATE</th><th>MEMO</th><th>BRANCH</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {journalsPage.pageItems.map((journal) => <tr key={journal.id}><td>{journal.journal_number}<br /><span className="muted">{journal.journal_date}</span></td><td>{journal.memo}</td><td>{journal.branch_name ?? 'Organization'}</td><td>{formatAction(journal.status)}</td><td>
            {journal.status === 'draft' && canApprove && journal.created_by !== user.id && <button className="button button-primary" type="button" disabled={busyId === journal.id} onClick={() => void runAction(`/accounting/journals/${journal.id}/post`, {}, journal.id)}>Approve & post</button>}
            {journal.status === 'posted' && !journal.reversal_of && canApprove && journal.created_by !== user.id && journal.approved_by !== user.id && <button className="button button-secondary" type="button" disabled={busyId === journal.id} onClick={() => {
              const reason = window.prompt('Reason for reversing this journal')
              if (reason?.trim()) void runAction(`/accounting/journals/${journal.id}/reverse`, { reversal_date: today, reason: reason.trim() }, journal.id)
            }}>Reverse</button>}
          </td></tr>)}
        </tbody></table></div><Pagination page={journalsPage.currentPage} totalPages={journalsPage.totalPages} total={journalsPage.total} pageSize={journalsPage.pageSize} onPage={journalsPage.setCurrentPage} label="Journal entries pagination" /></> : <EmptyState title="No journal entries" message="Manual and automatically posted journals will appear here." />}
      </section>}

      {view === 'postings' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">RECONCILIATION QUEUE</span><h2>Pending source postings</h2></div></div>
        {data.pending_source_events.length ? data.pending_source_events.map((event) => <article className="ecommerce-order-card" key={event.id}>
          <div className="ecommerce-order-heading"><div><h3>{event.description}</h3><p className="muted">{formatAction(event.event_type)} · {event.source_type} #{event.source_id} · {event.occurred_on}</p></div><strong>{event.currency_code} {Number(event.amount).toFixed(2)}</strong></div>
          <p className="muted">{event.failure_reason ?? 'Waiting for an accounting mapping or open period.'}</p>
          {canManage && <button className="button button-secondary" type="button" disabled={busyId === event.id} onClick={() => void runAction(`/accounting/source-events/${event.id}/retry`, {}, event.id)}>Retry posting</button>}
        </article>) : <EmptyState title="No pending source events" message="There are no operational events waiting for accounting configuration." />}
      </section>}

      {view === 'reports' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">FINANCIAL REPORTS</span><h2>{formatAction(reportType)}</h2></div></div>
        <form className="storefront-checkout-form" onSubmit={(event) => { event.preventDefault(); void loadReport() }}>
          <label>Report<select value={reportType} onChange={(event) => setReportType(event.target.value)}><option value="trial-balance">Trial balance</option><option value="profit-and-loss">Profit & loss</option><option value="balance-sheet">Balance sheet</option></select></label>
          <label>From<input type="date" value={reportFrom} onChange={(event) => setReportFrom(event.target.value)} required /></label>
          <label>To<input type="date" value={reportTo} onChange={(event) => setReportTo(event.target.value)} required /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Run report</button>
        </form>
        {report && <><div className="table-scroll"><table><thead><tr><th>ACCOUNT</th><th>TYPE</th><th>DEBITS</th><th>CREDITS</th><th>DEBIT BALANCE</th><th>CREDIT BALANCE</th></tr></thead><tbody>
          {report.data.map((row) => <tr key={`${row.account_id}-${row.currency_code}`}><td>{row.code} · {row.name}</td><td>{formatAction(row.account_type)}</td><td>{row.currency_code} {row.debit_total}</td><td>{row.currency_code} {row.credit_total}</td><td>{row.currency_code} {row.debit_balance}</td><td>{row.currency_code} {row.credit_balance}</td></tr>)}
        </tbody><tfoot>{report.totals.map((total) => <tr key={total.currency_code}><th colSpan={2}>{total.currency_code} totals</th><th>{total.debit}</th><th>{total.credit}</th><th colSpan={2}></th></tr>)}</tfoot></table></div>{reportType === 'trial-balance' && report.totals.some((total) => total.debit !== total.credit) && <div className="notice error" role="alert">Trial balance is out of balance. Investigate before closing the period.</div>}</>}
      </section>}
    </div>
  )
}

function CrmWorkspacePage({
  data,
  user,
  section,
  onRefresh,
}: {
  data: CrmWorkspace | null
  user: AuthUser
  section?: string
  onRefresh: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [today] = useState(() => new Date().toISOString().slice(0, 10))
  const canManageLeads = user.permissions.includes('crm.leads.manage')
  const canManageTasks = user.permissions.includes('crm.tasks.manage')
  const canManageCampaigns = user.permissions.includes('crm.campaigns.manage')
  const canApproveCampaigns = user.permissions.includes('crm.campaigns.approve')
  const canManageSegments = user.permissions.includes('crm.segments.manage')
  const canManageTeams = user.permissions.includes('crm.teams.manage')
  const canManageExpenses = user.permissions.includes('crm.budgets.manage')
  const canApproveExpenses = user.permissions.includes('crm.budgets.approve')
  const canManageConsent = user.permissions.includes('crm.consent.manage')
  const branchOptions = data?.branches ?? []

  async function submitForm(
    event: FormEvent<HTMLFormElement>,
    endpoint: string,
    method: 'POST' | 'PUT' | 'PATCH',
    buildPayload: (form: FormData) => Record<string, unknown>,
  ) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await apiRequest(endpoint, { method, body: JSON.stringify(buildPayload(form)) })
      setNotice('Your CRM update has been saved.')
      if (method === 'POST') formElement.reset()
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function action(endpoint: string, method: 'POST' | 'PATCH' | 'PUT', body?: Record<string, unknown>) {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await apiRequest(endpoint, {
        method,
        ...(body ? { body: JSON.stringify(body) } : {}),
      })
      setNotice('Your CRM action has been completed.')
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  if (!data) return <LoadingState />

  const view = section || 'leads'

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">CUSTOMER RELATIONSHIPS · PHASE 13</span><h1>CRM & marketing</h1><p className="muted">A focused workspace for customer enquiries, consent, campaigns, tasks, and measurable conversions.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
      </div>
      <div className="notice info" role="note">Use CRM fields for contact and marketing context only. Do not enter clinical notes, diagnoses, treatment details, or other patient records here. Marketing outreach must follow each customer’s recorded channel consent.</div>
      {error && <div className="notice error" role="alert">{error}</div>}
      {notice && <div className="notice success" role="status">{notice}</div>}

      <section className="workspace-metrics">
        <article className="metric-card"><span>New enquiries</span><strong>{data.summary.new_leads}</strong></article>
        <article className="metric-card"><span>Open tasks</span><strong>{data.summary.open_tasks}</strong></article>
        <article className="metric-card"><span>Campaign approvals</span><strong>{data.summary.pending_campaigns}</strong></article>
        <article className="metric-card"><span>Expense approvals</span><strong>{data.summary.pending_expenses}</strong></article>
        <article className="metric-card"><span>Linked conversions</span><strong>{data.summary.conversions}</strong></article>
      </section>

      {view === 'leads' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">ENQUIRIES · LEAD PIPELINE</span><h2>Customer leads</h2><p className="muted">Contact permission is separate from marketing opt-in. Leads are scoped to assigned branches.</p></div></div>
        {canManageLeads && <form className="storefront-checkout-form" onSubmit={(event) => void submitForm(event, '/crm/leads', 'POST', (form) => ({
          branch_id: form.get('branch_id') ? Number(form.get('branch_id')) : null,
          source: String(form.get('source') ?? 'other'),
          name: String(form.get('name') ?? ''),
          email: String(form.get('email') ?? '') || null,
          phone: String(form.get('phone') ?? '') || null,
          subject: String(form.get('subject') ?? '') || null,
          message: String(form.get('message') ?? '') || null,
          assigned_to: form.get('assigned_to') ? Number(form.get('assigned_to')) : null,
          contact_consent: form.get('contact_consent') === 'on',
          utm_source: String(form.get('utm_source') ?? '') || null,
          utm_medium: String(form.get('utm_medium') ?? '') || null,
          utm_campaign: String(form.get('utm_campaign') ?? '') || null,
        }))}>
          <label>Location<select name="branch_id" required defaultValue={String(branchOptions[0]?.id ?? '')}>{branchOptions.map((branch) => <option value={branch.id} key={branch.id}>{branch.name}</option>)}</select></label>
          <label>Lead source<select name="source" defaultValue="phone"><option value="website">Website</option><option value="phone">Phone</option><option value="walk_in">Walk-in</option><option value="referral">Referral</option><option value="social">Social</option><option value="campaign">Campaign</option><option value="other">Other</option></select></label>
          <label>Name<input name="name" required maxLength={180} /></label>
          <label>Email<input name="email" type="email" maxLength={255} /></label>
          <label>Phone<input name="phone" type="tel" maxLength={40} /></label>
          <label>Subject<input name="subject" maxLength={180} /></label>
          <label>Assigned to<select name="assigned_to" defaultValue=""><option value="">Unassigned</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select></label>
          <label>UTM source<input name="utm_source" maxLength={160} /></label>
          <label>UTM medium<input name="utm_medium" maxLength={160} /></label>
          <label>UTM campaign<input name="utm_campaign" maxLength={160} /></label>
          <label className="storefront-consent-check"><input name="contact_consent" type="checkbox" /> Customer agreed to be contacted</label>
          <label className="storefront-wide-field">Enquiry notes (non-clinical only)<textarea name="message" maxLength={2000} /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Add enquiry</button>
        </form>}
        {data.leads.length ? <div className="crm-record-list">{data.leads.map((lead) => <article className="ecommerce-order-card" key={lead.id}>
          <div className="ecommerce-order-heading"><div><h3>{lead.name}</h3><p className="muted">{lead.subject || 'Enquiry'} · {lead.source} · {lead.branch_name || 'Organization'} · {new Date(lead.created_at).toLocaleDateString()}</p></div><strong>{formatAction(lead.status)}</strong></div>
          <p>{lead.email || 'No email'}{lead.phone ? ` · ${lead.phone}` : ''} · Contact permission: {lead.contact_consent ? 'recorded' : 'not recorded'}</p>
          {lead.message && <p className="muted">{lead.message}</p>}
          {(lead.utm_source || lead.utm_campaign) && <small className="muted">Attribution: {[lead.utm_source, lead.utm_medium, lead.utm_campaign].filter(Boolean).join(' / ')}</small>}
          {canManageLeads && lead.status !== 'converted' && <div className="crm-action-row">
            <label>Status<select value={lead.status} onChange={(event) => void action(`/crm/leads/${lead.id}`, 'PATCH', { status: event.target.value })}><option value="new">New</option><option value="contacted">Contacted</option><option value="qualified">Qualified</option><option value="lost">Lost</option></select></label>
            <label>Owner<select value={lead.assigned_to ?? ''} onChange={(event) => void action(`/crm/leads/${lead.id}`, 'PATCH', { assigned_to: event.target.value ? Number(event.target.value) : null })}><option value="">Unassigned</option>{data.members.map((member) => <option value={member.id} key={member.id}>{member.name}</option>)}</select></label>
            {canManageConsent && lead.customer_id && <div className="crm-action-row"><button className="button button-secondary" type="button" disabled={busy} onClick={() => void action(`/crm/customers/${lead.customer_id}/preferences`, 'PUT', { channel: 'email', status: 'opted_in' })}>Opt in email</button><button className="button button-secondary" type="button" disabled={busy} onClick={() => void action(`/crm/customers/${lead.customer_id}/preferences`, 'PUT', { channel: 'email', status: 'opted_out' })}>Opt out email</button></div>}
            <form className="crm-conversion-form" onSubmit={(event) => {
              const form = new FormData(event.currentTarget)
              event.preventDefault()
              void action(`/crm/leads/${lead.id}/conversions`, 'POST', {
                customer_id: Number(form.get('customer_id')),
                appointment_id: form.get('appointment_id') ? Number(form.get('appointment_id')) : null,
                order_id: form.get('order_id') ? Number(form.get('order_id')) : null,
                sale_id: form.get('sale_id') ? Number(form.get('sale_id')) : null,
              })
            }}>
              <input name="customer_id" type="number" min="1" defaultValue={lead.customer_id ?? ''} placeholder="Customer ID" required />
              <input name="appointment_id" type="number" min="1" placeholder="Booking ID" />
              <input name="order_id" type="number" min="1" placeholder="Paid order ID" />
              <input name="sale_id" type="number" min="1" placeholder="Completed sale ID" />
              <button className="button button-secondary" type="submit" disabled={busy}>Link booking / purchase</button>
            </form>
          </div>}
        </article>)}</div> : <EmptyState title="No enquiries yet" message="Website enquiries and staff-created leads will appear here." />}
      </section>}

      {view === 'segments' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">CUSTOMER CONSENT · SAFE SEGMENTS</span><h2>Customer segments</h2><p className="muted">Only contact channel, registration source, and branch are valid criteria; clinical fields are not supported.</p></div></div>
        {canManageSegments && <form className="storefront-checkout-form" onSubmit={(event) => void submitForm(event, '/crm/segments', 'POST', (form) => ({
          name: String(form.get('name') ?? ''),
          branch_id: form.get('branch_id') ? Number(form.get('branch_id')) : null,
          criteria: {
            ...(form.get('registration_source') ? { registration_source: String(form.get('registration_source')) } : {}),
            ...(form.get('email_marketing') ? { email_marketing: String(form.get('email_marketing')) } : {}),
          },
        }))}>
          <label>Segment name<input name="name" required maxLength={120} /></label>
          <label>Branch<select name="branch_id" defaultValue=""><option value="">All assigned branches</option>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label>Registration source<select name="registration_source" defaultValue=""><option value="">Any source</option><option value="website">Website</option><option value="walk_in">Walk-in</option><option value="referral">Referral</option><option value="phone">Phone</option><option value="other">Other</option></select></label>
          <label>Email marketing consent<select name="email_marketing" defaultValue=""><option value="">Any consent status</option><option value="opted_in">Opted in</option><option value="opted_out">Opted out</option></select></label>
          <button className="button button-primary" type="submit" disabled={busy}>Save segment</button>
        </form>}
        {data.segments.length ? <div className="crm-pill-list">{data.segments.map((segment) => <span className="status-pill" key={segment.id}>{segment.name}</span>)}</div> : <p className="muted">No customer segments configured.</p>}
      </section>}

      {view === 'campaigns' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">MARKETING PLANS · CHANNELS</span><h2>Campaigns & approvals</h2></div></div>
        {canManageCampaigns && <form className="storefront-checkout-form" onSubmit={(event) => void submitForm(event, '/crm/campaigns', 'POST', (form) => ({
          branch_id: form.get('branch_id') ? Number(form.get('branch_id')) : null,
          segment_id: form.get('segment_id') ? Number(form.get('segment_id')) : null,
          team_id: form.get('team_id') ? Number(form.get('team_id')) : null,
          name: String(form.get('name') ?? ''),
          objective: String(form.get('objective') ?? '') || null,
          channels: form.getAll('channels').map(String),
          budget_amount: String(form.get('budget_amount') ?? '') || null,
          currency_code: String(form.get('currency_code') ?? 'KES'),
          starts_on: String(form.get('starts_on') ?? '') || null,
          ends_on: String(form.get('ends_on') ?? '') || null,
        }))}>
          <label>Campaign name<input name="name" required maxLength={160} /></label>
          <label>Branch<select name="branch_id" defaultValue=""><option value="">Organization-wide</option>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label>Audience segment<select name="segment_id" defaultValue=""><option value="">No saved segment</option>{data.segments.map((segment) => <option key={segment.id} value={segment.id}>{segment.name}</option>)}</select></label>
          <label>Team<select name="team_id" defaultValue=""><option value="">Unassigned team</option>{data.teams.map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}</select></label>
          <label className="storefront-wide-field">Objective<textarea name="objective" maxLength={500} /></label>
          <fieldset className="storefront-address"><legend>Planned channels</legend>{['email', 'sms', 'whatsapp', 'social', 'website', 'events', 'referral'].map((channel) => <label className="storefront-consent-check" key={channel}><input name="channels" type="checkbox" value={channel} /> {formatAction(channel)}</label>)}</fieldset>
          <label>Budget amount<input name="budget_amount" type="number" min="0" step="0.01" /></label>
          <label>Currency<input name="currency_code" defaultValue="KES" minLength={3} maxLength={3} /></label>
          <label>Starts<input name="starts_on" type="date" /></label>
          <label>Ends<input name="ends_on" type="date" /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Create draft campaign</button>
        </form>}
        {data.campaigns.length ? <div className="crm-record-list">{data.campaigns.map((campaign) => <article className="ecommerce-order-card" key={campaign.id}>
          <div className="ecommerce-order-heading"><div><h3>{campaign.name}</h3><p className="muted">{campaign.branch_name || 'Organization-wide'} · {campaign.segment_name || 'No saved segment'} · {campaign.currency_code} {campaign.budget_amount || '0.00'}</p></div><strong>{formatAction(campaign.status)}</strong></div>
          {campaign.objective && <p>{campaign.objective}</p>}
          <p className="muted">Channels: {(() => { try { return (JSON.parse(campaign.channels) as string[]).map(formatAction).join(', ') } catch { return campaign.channels } })()}</p>
          <div className="crm-action-row">
            {campaign.status === 'draft' && canManageCampaigns && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void action(`/crm/campaigns/${campaign.id}/submit`, 'POST')}>Submit for approval</button>}
            {campaign.status === 'submitted' && canApproveCampaigns && campaign.submitted_by !== user.id && <button className="button button-primary" type="button" disabled={busy} onClick={() => void action(`/crm/campaigns/${campaign.id}/approve`, 'POST')}>Approve campaign</button>}
            {campaign.status === 'approved' && canManageCampaigns && <button className="button button-primary" type="button" disabled={busy} onClick={() => void action(`/crm/campaigns/${campaign.id}/status`, 'PATCH', { status: 'active' })}>Activate campaign</button>}
            {campaign.status === 'active' && canManageCampaigns && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void action(`/crm/campaigns/${campaign.id}/status`, 'PATCH', { status: 'completed' })}>Complete campaign</button>}
          </div>
        </article>)}</div> : <EmptyState title="No campaigns planned" message="Create a campaign with channel, audience, team, dates, and a proposed budget." />}
      </section>}

      {view === 'tasks' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">TEAM FOLLOW-THROUGH</span><h2>Tasks & marketing team</h2></div></div>
        {canManageTasks && <form className="storefront-checkout-form" onSubmit={(event) => void submitForm(event, '/crm/tasks', 'POST', (form) => ({
          branch_id: form.get('branch_id') ? Number(form.get('branch_id')) : null,
          lead_id: form.get('lead_id') ? Number(form.get('lead_id')) : null,
          campaign_id: form.get('campaign_id') ? Number(form.get('campaign_id')) : null,
          assigned_to: Number(form.get('assigned_to')),
          title: String(form.get('title') ?? ''),
          details: String(form.get('details') ?? '') || null,
          priority: String(form.get('priority') ?? 'normal'),
          due_at: String(form.get('due_at') ?? '') || null,
        }))}>
          <label>Task title<input name="title" required maxLength={180} /></label>
          <label>Assigned to<select name="assigned_to" required defaultValue={String(user.id)}>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select></label>
          <label>Branch<select name="branch_id" defaultValue={String(branchOptions[0]?.id ?? '')}>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label>Related lead<select name="lead_id" defaultValue=""><option value="">No lead</option>{data.leads.map((lead) => <option key={lead.id} value={lead.id}>{lead.name} · #{lead.id}</option>)}</select></label>
          <label>Related campaign<select name="campaign_id" defaultValue=""><option value="">No campaign</option>{data.campaigns.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}</select></label>
          <label>Priority<select name="priority" defaultValue="normal"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></label>
          <label>Due date<input name="due_at" type="datetime-local" /></label>
          <label>Task details<textarea name="details" maxLength={2000} /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Assign task</button>
        </form>}
        {data.tasks.length ? <div className="crm-record-list">{data.tasks.map((task) => <article className="ecommerce-order-card" key={task.id}>
          <div className="ecommerce-order-heading"><div><h3>{task.title}</h3><p className="muted">{task.branch_name || 'Organization'} · {task.assigned_name} · {task.due_at ? new Date(task.due_at).toLocaleString() : 'No due date'}</p></div><strong>{formatAction(task.status)}</strong></div>
          {task.details && <p>{task.details}</p>}
          {canManageTasks && task.status !== 'completed' && task.status !== 'cancelled' && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void action(`/crm/tasks/${task.id}`, 'PATCH', { status: 'completed' })}>Mark complete</button>}
        </article>)}</div> : <p className="muted">No CRM tasks assigned yet.</p>}
        {canManageTeams && <form className="storefront-checkout-form" onSubmit={(event) => void submitForm(event, '/crm/teams', 'POST', (form) => ({
          name: String(form.get('name') ?? ''),
          description: String(form.get('description') ?? '') || null,
          member_ids: form.getAll('member_ids').map(Number),
        }))}>
          <label>New team name<input name="name" required maxLength={120} /></label>
          <label>Team description<input name="description" maxLength={500} /></label>
          <label>Team members<select name="member_ids" multiple size={4}>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select></label>
          <button className="button button-secondary" type="submit" disabled={busy}>Create marketing team</button>
        </form>}
        {data.teams.length > 0 && <div className="crm-pill-list">{data.teams.map((team) => <span className="status-pill" key={team.id}>{team.name}</span>)}</div>}
      </section>}

      {view === 'expenses' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">BUDGET CONTROL</span><h2>Marketing expenses</h2><p className="muted">Campaign-linked expenses are checked against the approved campaign budget and require a separate approver.</p></div></div>
        {canManageExpenses && <form className="storefront-checkout-form" onSubmit={(event) => void submitForm(event, '/crm/expenses', 'POST', (form) => ({
          branch_id: form.get('branch_id') ? Number(form.get('branch_id')) : null,
          campaign_id: form.get('campaign_id') ? Number(form.get('campaign_id')) : null,
          category: String(form.get('category') ?? ''),
          description: String(form.get('description') ?? ''),
          vendor: String(form.get('vendor') ?? '') || null,
          amount: String(form.get('amount') ?? ''),
          currency_code: String(form.get('currency_code') ?? 'KES'),
          incurred_on: String(form.get('incurred_on') ?? ''),
        }))}>
          <label>Campaign<select name="campaign_id" defaultValue=""><option value="">General marketing expense</option>{data.campaigns.filter((campaign) => ['approved', 'active'].includes(campaign.status)).map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}</select></label>
          <label>Branch<select name="branch_id" defaultValue={String(branchOptions[0]?.id ?? '')}>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label>Category<input name="category" required maxLength={60} /></label>
          <label>Description<input name="description" required maxLength={500} /></label>
          <label>Vendor<input name="vendor" maxLength={160} /></label>
          <label>Amount<input name="amount" type="number" min="0.01" step="0.01" required /></label>
          <label>Currency<input name="currency_code" defaultValue="KES" minLength={3} maxLength={3} required /></label>
          <label>Incurred on<input name="incurred_on" type="date" required defaultValue={today} /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Submit expense</button>
        </form>}
        {data.expenses.length ? <div className="crm-record-list">{data.expenses.map((expense) => <article className="ecommerce-order-card" key={expense.id}>
          <div className="ecommerce-order-heading"><div><h3>{expense.description}</h3><p className="muted">{expense.category} · {expense.campaign_name || 'General'} · submitted by {expense.submitted_by_name} · {expense.incurred_on}</p></div><strong>{expense.currency_code} {Number(expense.amount).toFixed(2)} · {formatAction(expense.status)}</strong></div>
          {expense.review_note && <p className="muted">{expense.review_note}</p>}
          {expense.status === 'submitted' && canApproveExpenses && expense.submitted_by !== user.id && <div className="crm-action-row"><button className="button button-primary" type="button" disabled={busy} onClick={() => void action(`/crm/expenses/${expense.id}/review`, 'POST', { decision: 'approved' })}>Approve expense</button><button className="button button-secondary" type="button" disabled={busy} onClick={() => {
            const review_note = window.prompt('Reason for rejecting this expense')
            if (review_note?.trim()) void action(`/crm/expenses/${expense.id}/review`, 'POST', { decision: 'rejected', review_note: review_note.trim() })
          }}>Reject</button></div>}
        </article>)}</div> : <p className="muted">No marketing expenses recorded.</p>}
      </section>}

      {view === 'attribution' && <section className="panel">
        <div className="section-heading"><div><span className="eyebrow">ATTRIBUTION · CRM CONVERSIONS</span><h2>Where conversions come from</h2><p className="muted">Only linked bookings and paid/completed purchases count. No clinical information is used.</p></div></div>
        {data.attribution.length ? <div className="table-scroll"><table><thead><tr><th>SOURCE</th><th>LINKED CONVERSIONS</th></tr></thead><tbody>{data.attribution.map((row) => <tr key={row.source}><td>{row.source || 'Unattributed'}</td><td>{row.conversions}</td></tr>)}</tbody></table></div> : <EmptyState title="No attributed conversions yet" message="Link qualified leads to a confirmed booking, paid online order, or completed POS sale to report attribution." />}
      </section>}
    </div>
  )
}

function WebsiteContentPage({
  pages,
  user,
  section,
  onRefresh,
}: {
  pages: WebsitePage[]
  user: AuthUser
  section?: string
  onRefresh: () => void
}) {
  type PageDraft = Omit<WebsitePage, 'id' | 'status' | 'published_at' | 'updated_at'> & { page_type: 'page' | 'journal' }
  type WebsiteRedirect = {
    id: number
    source_path: string
    target_path: string
    status_code: 301 | 302
    is_active: boolean
    created_at: string
  }
  const emptyDraft: PageDraft = {
    slug: '',
    page_type: 'page',
    title: '',
    eyebrow: '',
    summary: '',
    body: '',
    seo_title: '',
    seo_description: '',
  }
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [draft, setDraft] = useState<PageDraft>(emptyDraft)
  const [scheduleAt, setScheduleAt] = useState('')
  const [coverImage, setCoverImage] = useState<File | null>(null)
  const [redirects, setRedirects] = useState<WebsiteRedirect[]>([])
  const [redirectDraft, setRedirectDraft] = useState({ source_path: '', target_path: '', status_code: 301 as 301 | 302 })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const selectedPage = pages.find((page) => page.id === selectedId) ?? null
  const canManage = user.permissions.includes('website.content.manage')
  const canPublish = user.permissions.includes('website.content.publish')
  const view = section || 'pages'
  const pagesList = usePaginatedList(pages, 10)

  useEffect(() => {
    let active = true
    void apiRequest<{ redirects: WebsiteRedirect[] }>('/website/redirects')
      .then(({ redirects: records }) => {
        if (active) setRedirects(records)
      })
      .catch((requestError: unknown) => {
        if (active) setNotice(getErrorMessage(requestError))
      })
    return () => { active = false }
  }, [])

  function choosePage(page: WebsitePage) {
    setSelectedId(page.id)
    setDraft({
      slug: page.slug,
      page_type: page.page_type,
      title: page.title,
      eyebrow: page.eyebrow ?? '',
      summary: page.summary ?? '',
      body: page.body ?? '',
      seo_title: page.seo_title ?? '',
      seo_description: page.seo_description ?? '',
    })
    setCoverImage(null)
    setScheduleAt(page.published_at ? new Date(page.published_at).toISOString().slice(0, 16) : '')
    setNotice('')
  }

  function startPage() {
    setSelectedId(null)
    setDraft({ ...emptyDraft })
    setCoverImage(null)
    setScheduleAt('')
    setNotice('')
  }

  async function savePage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    try {
      let saved = await apiRequest<WebsitePage>(
        selectedId ? `/website/pages/${selectedId}` : '/website/pages',
        {
          method: selectedId ? 'PUT' : 'POST',
          body: JSON.stringify(draft),
        },
      )
      if (coverImage) {
        const upload = new FormData()
        upload.set('image', coverImage)
        saved = await apiRequest<WebsitePage>(`/website/pages/${saved.id}/media`, {
          method: 'POST',
          body: upload,
        })
        setCoverImage(null)
      }
      setSelectedId(saved.id)
      setDraft({
        slug: saved.slug,
        page_type: saved.page_type,
        title: saved.title,
        eyebrow: saved.eyebrow ?? '',
        summary: saved.summary ?? '',
        body: saved.body ?? '',
        seo_title: saved.seo_title ?? '',
        seo_description: saved.seo_description ?? '',
      })
      setNotice(selectedPage?.status === 'published'
        ? 'Page changes saved and are now live.'
        : 'Page saved as a draft. Publish it when the content is ready.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function removeCoverImage() {
    if (!selectedPage) return
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/website/pages/${selectedPage.id}/media`, { method: 'DELETE' })
      setNotice('Cover image removed.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function createRedirect(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ redirect: WebsiteRedirect }>('/website/redirects', {
        method: 'POST',
        body: JSON.stringify(redirectDraft),
      })
      setRedirects((current) => [...current.filter((item) => item.id !== result.redirect.id), result.redirect].sort((a, b) => a.source_path.localeCompare(b.source_path)))
      setRedirectDraft({ source_path: '', target_path: '', status_code: 301 })
      setNotice('Redirect saved.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function removeRedirect(id: number) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/website/redirects/${id}`, { method: 'DELETE' })
      setRedirects((current) => current.filter((redirect) => redirect.id !== id))
      setNotice('Redirect removed.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function setPublication(status: 'published' | 'draft' | 'archived') {
    if (!selectedPage) return
    setBusy(true)
    setNotice('')
    try {
      await apiRequest<WebsitePage>(`/website/pages/${selectedPage.id}/publication`, {
        method: 'PATCH',
        body: JSON.stringify({
          status,
          published_at: status === 'published' && scheduleAt
            ? new Date(scheduleAt).toISOString()
            : null,
        }),
      })
      setNotice(status === 'published'
        ? scheduleAt ? 'Publication scheduled.' : 'Page published.'
        : status === 'archived' ? 'Page archived.' : 'Page returned to draft.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="content-stack website-content-workspace">
      <div className="welcome-row">
        <div><span className="eyebrow">PUBLIC WEBSITE</span><h1>Website content</h1><p className="muted">Manage public page copy and search metadata. Text is escaped on display; drafts are not visible to customers.</p></div>
        <div className="welcome-actions">
          {view === 'pages' && canManage && <button className="button button-primary" type="button" onClick={startPage}>＋ New page</button>}
          <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
        </div>
      </div>
      {notice && <div className="notice info" role="status">{notice}</div>}
      {view === 'pages' && <div className="website-content-layout">
        <section className="panel website-page-list" aria-label="Website pages">
          <div className="welcome-row"><div><span className="eyebrow">CONTENT LIBRARY</span><h2>Pages</h2></div><span className="status-pill">{pages.length}</span></div>
          {pages.length ? pagesList.pageItems.map((page) => (
            <button
              className={`website-page-item${page.id === selectedId ? ' is-selected' : ''}`}
              type="button"
              key={page.id}
              onClick={() => choosePage(page)}
            >
              <span><strong>{page.title}</strong><small>/{page.slug}</small></span>
              <span className="status-pill">{page.page_type === 'journal' ? 'Journal' : 'Page'} · {page.status}</span>
            </button>
          )) : <EmptyState title="No pages yet" message="Create a draft page to begin managing your public website content." />}
          <Pagination page={pagesList.currentPage} totalPages={pagesList.totalPages} total={pagesList.total} pageSize={pagesList.pageSize} onPage={pagesList.setCurrentPage} label="Website pages pagination" />
        </section>
        <section className="panel website-page-editor">
          <div className="welcome-row"><div><span className="eyebrow">{selectedPage ? 'PAGE EDITOR' : 'NEW DRAFT'}</span><h2>{selectedPage?.title ?? 'Create a page'}</h2></div>{selectedPage && <span className="status-pill">{selectedPage.status}</span>}</div>
          {canManage ? (
            <form className="website-content-form" onSubmit={(event) => void savePage(event)}>
              <label>Content type<select value={draft.page_type} onChange={(event) => setDraft({ ...draft, page_type: event.target.value as 'page' | 'journal' })}><option value="page">Website page</option><option value="journal">Journal article</option></select></label>
              <label>URL slug<input required pattern="[A-Za-z0-9_-]+" maxLength={120} value={draft.slug} onChange={(event) => setDraft({ ...draft, slug: event.target.value })} placeholder="home" /></label>
              <label>Page title<input required maxLength={180} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label>
              <label>Eyebrow<input maxLength={120} value={draft.eyebrow ?? ''} onChange={(event) => setDraft({ ...draft, eyebrow: event.target.value })} /></label>
              <label>Summary<input maxLength={500} value={draft.summary ?? ''} onChange={(event) => setDraft({ ...draft, summary: event.target.value })} /></label>
              <label>Body copy<textarea rows={6} maxLength={10000} value={draft.body ?? ''} onChange={(event) => setDraft({ ...draft, body: event.target.value })} /></label>
              <fieldset className="website-seo-fields"><legend>Page image</legend>
                {selectedPage?.cover_image_url && <div><img className="website-page-cover-preview" src={selectedPage.cover_image_url} alt={`Cover for ${selectedPage.title}`} /><button className="text-button" type="button" disabled={busy} onClick={() => void removeCoverImage()}>Remove image</button></div>}
                <label>Cover image<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setCoverImage(event.target.files?.[0] ?? null)} /></label>
                <small className="muted">JPG, PNG or WebP, up to 5 MB. Saving the page uploads the selected image.</small>
              </fieldset>
              <fieldset className="website-seo-fields"><legend>Search appearance</legend>
                <label>SEO title<input maxLength={70} value={draft.seo_title ?? ''} onChange={(event) => setDraft({ ...draft, seo_title: event.target.value })} /></label>
                <label>Meta description<textarea rows={3} maxLength={160} value={draft.seo_description ?? ''} onChange={(event) => setDraft({ ...draft, seo_description: event.target.value })} /></label>
              </fieldset>
              <div className="dialog-actions">
                <button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save draft'}</button>
                {selectedPage && canPublish && <label className="website-schedule">Publish at<input type="datetime-local" value={scheduleAt} onChange={(event) => setScheduleAt(event.target.value)} /></label>}
              </div>
              {selectedPage && canPublish && <div className="dialog-actions website-publication-actions">
                <button className="button button-secondary" type="button" disabled={busy} onClick={() => void setPublication('published')}>Publish / schedule</button>
                {selectedPage.status !== 'draft' && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void setPublication('draft')}>Unpublish</button>}
                {selectedPage.status !== 'archived' && <button className="text-button" type="button" disabled={busy} onClick={() => void setPublication('archived')}>Archive</button>}
              </div>}
            </form>
          ) : selectedPage ? (
            <div className="website-page-preview"><p><strong>{selectedPage.eyebrow}</strong></p><p>{selectedPage.summary}</p><p>{selectedPage.body}</p><small>SEO title: {selectedPage.seo_title || 'Not set'}</small><br /><small>SEO description: {selectedPage.seo_description || 'Not set'}</small></div>
          ) : <EmptyState title="Select a page" message="Choose a page from the content library to review its copy and metadata." />}
          {!canManage && canPublish && <p className="muted">Your account can publish pages but cannot edit their content.</p>}
        </section>
      </div>}
      {view === 'redirects' && <section className="panel website-redirects">
        <div className="welcome-row"><div><span className="eyebrow">SEO · URL MANAGEMENT</span><h2>Redirects</h2><p className="muted">Redirects are scoped to this organization. Enter paths after the organization slug; external destinations are not allowed.</p></div><span className="status-pill">{redirects.length}</span></div>
        {canManage && <form className="website-content-form website-redirect-form" onSubmit={(event) => void createRedirect(event)}>
          <label>Old path<input required pattern="/[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*" maxLength={255} value={redirectDraft.source_path} onChange={(event) => setRedirectDraft({ ...redirectDraft, source_path: event.target.value })} placeholder="/old-page" /></label>
          <label>New path<input required pattern="/[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*" maxLength={255} value={redirectDraft.target_path} onChange={(event) => setRedirectDraft({ ...redirectDraft, target_path: event.target.value })} placeholder="/new-page" /></label>
          <label>Response<select value={redirectDraft.status_code} onChange={(event) => setRedirectDraft({ ...redirectDraft, status_code: Number(event.target.value) as 301 | 302 })}><option value={301}>301 · Permanent</option><option value={302}>302 · Temporary</option></select></label>
          <button className="button button-primary" type="submit" disabled={busy}>Add redirect</button>
        </form>}
        {redirects.length ? <div className="crm-record-list">{redirects.map((redirect) => <article className="ecommerce-order-card" key={redirect.id}><div className="ecommerce-order-heading"><div><strong>{redirect.source_path} → {redirect.target_path}</strong><p className="muted">{redirect.status_code} · {redirect.is_active ? 'Active' : 'Inactive'}</p></div>{canManage && <button className="text-button" type="button" disabled={busy} onClick={() => void removeRedirect(redirect.id)}>Remove</button>}</div></article>)}</div> : <p className="muted">No redirects configured.</p>}
      </section>}
    </div>
  )
}

function WebsiteGrowthPage({
  data,
  user,
  section: sectionKey,
  onRefresh,
}: {
  data: WebsiteGrowthData | null
  user: AuthUser
  section?: string
  onRefresh: () => void
}) {
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [program, setProgram] = useState({ points_per_100_currency: 1, points_per_currency_redemption: 10, minimum_redemption_points: 100, is_active: false })
  const [section, setSection] = useState({ slug: '', title: '', description: '', sort_order: 0, is_active: true, product_ids: [] as number[] })
  const [socialDraft, setSocialDraft] = useState<Record<string, string>>({})
  const [selectedPromotion, setSelectedPromotion] = useState<number | null>(null)
  const [promotionDraft, setPromotionDraft] = useState({
    title: '',
    message: '',
    coupon_code: '',
    discount_type: '',
    discount_value: '',
    minimum_subtotal: '0',
    maximum_redemptions: '',
    starts_at: '',
    ends_at: '',
    status: 'draft',
    show_publicly: false,
  })
  const permissions = user.permissions
  const canAdjustRewards = permissions.includes('loyalty.adjust')
  const platforms = ['instagram', 'facebook', 'tiktok', 'whatsapp', 'youtube']

  useEffect(() => {
    const saved = data?.loyalty?.program
    if (saved) {
      setProgram({
        points_per_100_currency: saved.points_per_100_currency,
        points_per_currency_redemption: saved.points_per_currency_redemption,
        minimum_redemption_points: saved.minimum_redemption_points,
        is_active: saved.is_active,
      })
    }
  }, [data?.loyalty?.program])

  useEffect(() => {
    const links = Object.fromEntries((data?.socialLinks ?? []).map((link) => [link.platform, link.url]))
    setSocialDraft(links)
  }, [data?.socialLinks])

  async function saveProgram(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/website/loyalty/program', { method: 'PUT', body: JSON.stringify(program) })
      setNotice('Rewards programme settings saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function adjustPoints(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/website/loyalty/adjustments', {
        method: 'POST',
        body: JSON.stringify({
          customer_id: Number(form.get('customer_id')),
          points: Number(form.get('points')),
          reason: String(form.get('reason') ?? ''),
        }),
      })
      setNotice('Customer points adjusted with an audit record.')
      event.currentTarget.reset()
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveSection(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/website/product-sections', { method: 'PUT', body: JSON.stringify(section) })
      setSection({ slug: '', title: '', description: '', sort_order: 0, is_active: true, product_ids: [] })
      setNotice('Featured product section saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function savePromotion(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const couponCode = String(form.get('coupon_code') ?? '').trim()
    const discountType = String(form.get('discount_type') ?? '')
    const discountValue = String(form.get('discount_value') ?? '')
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(selectedPromotion ? `/website/promotions/${selectedPromotion}` : '/website/promotions', {
        method: selectedPromotion ? 'PUT' : 'POST',
        body: JSON.stringify({
          title: String(form.get('title') ?? ''),
          message: String(form.get('message') ?? '') || null,
          coupon_code: couponCode || null,
          discount_type: discountType || null,
          discount_value: discountValue ? Number(discountValue) : null,
          minimum_subtotal: Number(form.get('minimum_subtotal') ?? 0),
          maximum_redemptions: String(form.get('maximum_redemptions') ?? '') ? Number(form.get('maximum_redemptions')) : null,
          starts_at: String(form.get('starts_at') ?? '') || null,
          ends_at: String(form.get('ends_at') ?? '') || null,
          status: String(form.get('status') ?? 'draft'),
          show_publicly: form.get('show_publicly') === 'on',
        }),
      })
      setSelectedPromotion(null)
      setPromotionDraft({ title: '', message: '', coupon_code: '', discount_type: '', discount_value: '', minimum_subtotal: '0', maximum_redemptions: '', starts_at: '', ends_at: '', status: 'draft', show_publicly: false })
      event.currentTarget.reset()
      setNotice('Promotion saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function moderateReview(reviewId: number, status: 'approved' | 'rejected' | 'hidden', featured = false) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/website/reviews/${reviewId}`, {
        method: 'PATCH',
        body: JSON.stringify({ status, featured }),
      })
      setNotice('Review moderation saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveSocialLinks(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const links = platforms.filter((platform) => socialDraft[platform]?.trim()).map((platform) => ({
      platform,
      url: socialDraft[platform].trim(),
      is_active: true,
    }))
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/website/social-links', { method: 'PUT', body: JSON.stringify({ links }) })
      setNotice('Public social links saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  function editPromotion(promotion: NonNullable<WebsiteGrowthData['promotions']>['data'][number]) {
    setSelectedPromotion(promotion.id)
    setPromotionDraft({
      title: promotion.title,
      message: promotion.message ?? '',
      coupon_code: promotion.coupon_code ?? '',
      discount_type: promotion.discount_type ?? '',
      discount_value: promotion.discount_value === null ? '' : String(promotion.discount_value),
      minimum_subtotal: String(promotion.minimum_subtotal),
      maximum_redemptions: promotion.maximum_redemptions === null ? '' : String(promotion.maximum_redemptions),
      starts_at: promotion.starts_at?.slice(0, 16) ?? '',
      ends_at: promotion.ends_at?.slice(0, 16) ?? '',
      status: promotion.status,
      show_publicly: promotion.show_publicly,
    })
    setNotice('Choose “Save promotion” after entering its details below. Existing redemption counts are preserved.')
  }

  return (
    <div className="content-stack website-content-workspace">
      <div className="welcome-row">
        <div><span className="eyebrow">PUBLIC WEBSITE</span><h1>Website growth</h1><p className="muted">Manage customer rewards, approved reviews, product collections, campaigns, and social links.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
      </div>
      <div className="notice info">Online payments, delivery rates, outbound newsletter email, and social publishing are not connected. Promotions can be displayed publicly; coupon discounts are applied only to eligible online orders.</div>
      {notice && <div className="notice info" role="status">{notice}</div>}

      {(sectionKey === 'loyalty' || !sectionKey) && data?.loyalty && permissions.includes('loyalty.manage') && <section className="panel content-stack">
        <div><span className="eyebrow">OSHEL REWARDS</span><h2>Loyalty programme</h2><p className="muted">Points are earned from completed POS sales and reversed when those sales are refunded or voided.</p></div>
        <form className="website-content-form" onSubmit={(event) => void saveProgram(event)}>
          <label>Points per 100 currency units<input type="number" min="0" max="100000" required value={program.points_per_100_currency} onChange={(event) => setProgram({ ...program, points_per_100_currency: Number(event.target.value) })} /></label>
          <label>Points per currency unit at redemption<input type="number" min="1" max="100000" required value={program.points_per_currency_redemption} onChange={(event) => setProgram({ ...program, points_per_currency_redemption: Number(event.target.value) })} /></label>
          <label>Minimum redemption points<input type="number" min="1" max="10000000" required value={program.minimum_redemption_points} onChange={(event) => setProgram({ ...program, minimum_redemption_points: Number(event.target.value) })} /></label>
          <label className="storefront-consent-check"><input type="checkbox" checked={program.is_active} onChange={(event) => setProgram({ ...program, is_active: event.target.checked })} /> Enable rewards earning</label>
          <button className="button button-primary" type="submit" disabled={busy}>Save programme</button>
        </form>
        <div className="schedule-resource-list"><h3>Customer balances</h3>{data.loyalty.accounts.length ? data.loyalty.accounts.map((account) => <article className="schedule-resource-row" key={account.customer_id}><span><strong>{account.name}</strong><small>{account.email || 'No email'} · {account.tier}</small></span><strong>{account.points_balance.toLocaleString()} points</strong></article>) : <EmptyState title="No rewards accounts yet" message="Eligible customers get an account when they make a completed POS purchase." compact />}</div>
        {canAdjustRewards && <form className="resource-create-form" onSubmit={(event) => void adjustPoints(event)}><label>Customer<select name="customer_id" required defaultValue=""><option value="" disabled>Select customer</option>{data.loyalty.accounts.map((account) => <option key={account.customer_id} value={account.customer_id}>{account.name} · {account.points_balance} pts</option>)}</select></label><label>Points adjustment<input name="points" type="number" required min={-1000000} max={1000000} /></label><label>Reason<input name="reason" required minLength={3} maxLength={500} /></label><button className="button button-secondary" type="submit" disabled={busy}>Adjust points</button></form>}
      </section>}

      {(sectionKey === 'reviews' || !sectionKey) && data?.reviews && permissions.includes('website.reviews.manage') && <section className="panel content-stack">
        <div><span className="eyebrow">CUSTOMER TRUST</span><h2>Review moderation</h2><p className="muted">Only reviews matched to completed purchases or visits are marked verified; publish after moderation.</p></div>
        {data.reviews.data.length ? data.reviews.data.map((review) => <article className="schedule-resource-row" key={review.id}><span><strong>{review.rating}/5 · {review.customer_name ?? 'Customer'} · {review.product_name ?? review.service_name ?? 'Verified visit'}</strong><small>{review.review_text}</small><small>{review.status}{review.featured ? ' · featured' : ''}</small></span><span className="appointment-actions">{review.status !== 'approved' && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void moderateReview(review.id, 'approved')}>Approve</button>}{review.status === 'approved' && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void moderateReview(review.id, 'approved', !review.featured)}>{review.featured ? 'Unfeature' : 'Feature'}</button>}<button className="text-button appointment-cancel" type="button" disabled={busy} onClick={() => void moderateReview(review.id, 'rejected')}>Reject</button></span></article>) : <EmptyState title="No reviews to moderate" message="Customer reviews from verified purchases and visits will appear here." compact />}
      </section>}

      {(sectionKey === 'collections' || !sectionKey) && data?.sections && permissions.includes('website.merchandising.manage') && <section className="panel content-stack">
        <div><span className="eyebrow">PRODUCT MERCHANDISING</span><h2>Featured collections</h2><p className="muted">Choose products and their order for public storefront collection blocks.</p></div>
        {data.sections.map((item) => <article className="schedule-resource-row" key={item.id}><span><strong>{item.title}</strong><small>/{item.slug} · {item.product_ids.length} selected products · {item.is_active ? 'Public' : 'Hidden'}</small></span><button className="text-button" type="button" onClick={() => setSection({ slug: item.slug, title: item.title, description: item.description ?? '', sort_order: item.sort_order, is_active: item.is_active, product_ids: item.product_ids })}>Edit</button></article>)}
        <form className="website-content-form" onSubmit={(event) => void saveSection(event)}>
          <label>Collection slug<input required pattern="[A-Za-z0-9_-]+" maxLength={80} value={section.slug} onChange={(event) => setSection({ ...section, slug: event.target.value })} /></label>
          <label>Heading<input required maxLength={120} value={section.title} onChange={(event) => setSection({ ...section, title: event.target.value })} /></label>
          <label>Description<input maxLength={500} value={section.description} onChange={(event) => setSection({ ...section, description: event.target.value })} /></label>
          <label>Display order<input type="number" min="0" max="1000" value={section.sort_order} onChange={(event) => setSection({ ...section, sort_order: Number(event.target.value) })} /></label>
          <label className="storefront-consent-check"><input type="checkbox" checked={section.is_active} onChange={(event) => setSection({ ...section, is_active: event.target.checked })} /> Show collection publicly</label>
          <fieldset className="website-seo-fields"><legend>Products</legend>{data.products?.length ? data.products.map((product) => <label className="storefront-consent-check" key={product.id}><input type="checkbox" checked={section.product_ids.includes(product.id)} onChange={(event) => setSection({ ...section, product_ids: event.target.checked ? [...section.product_ids, product.id] : section.product_ids.filter((id) => id !== product.id) })} /> {product.name} · {product.sku}</label>) : <p className="muted">No products available. Check the inventory catalogue first.</p>}</fieldset>
          <button className="button button-primary" type="submit" disabled={busy}>Save collection</button>
        </form>
      </section>}

      {(sectionKey === 'promotions' || !sectionKey) && data?.promotions && permissions.includes('website.marketing.manage') && <section className="panel content-stack">
        <div><span className="eyebrow">CAMPAIGNS</span><h2>Promotions & coupons</h2><p className="muted">Public banners are informational; coupons apply to online order totals before payment integration.</p></div>
        {data.promotions.data.map((promotion) => <article className="schedule-resource-row" key={promotion.id}><span><strong>{promotion.title}{promotion.coupon_code ? ` · ${promotion.coupon_code}` : ''}</strong><small>{promotion.status} · {promotion.redemption_count} redemptions{promotion.maximum_redemptions ? ` / ${promotion.maximum_redemptions}` : ''}</small></span><button className="text-button" type="button" onClick={() => editPromotion(promotion)}>Edit</button></article>)}
        <form className="website-content-form" onSubmit={(event) => void savePromotion(event)}>
          <label>Promotion title<input name="title" required maxLength={160} value={promotionDraft.title} onChange={(event) => setPromotionDraft({ ...promotionDraft, title: event.target.value })} /></label><label>Public message<input name="message" maxLength={500} value={promotionDraft.message} onChange={(event) => setPromotionDraft({ ...promotionDraft, message: event.target.value })} /></label>
          <label>Coupon code<input name="coupon_code" maxLength={40} value={promotionDraft.coupon_code} onChange={(event) => setPromotionDraft({ ...promotionDraft, coupon_code: event.target.value })} /></label><label>Discount type<select name="discount_type" value={promotionDraft.discount_type} onChange={(event) => setPromotionDraft({ ...promotionDraft, discount_type: event.target.value })}><option value="">No discount code</option><option value="percent">Percent</option><option value="fixed">Fixed amount</option></select></label><label>Discount value<input name="discount_value" type="number" min="0.01" step="0.01" value={promotionDraft.discount_value} onChange={(event) => setPromotionDraft({ ...promotionDraft, discount_value: event.target.value })} /></label>
          <label>Minimum subtotal<input name="minimum_subtotal" type="number" min="0" step="0.01" value={promotionDraft.minimum_subtotal} onChange={(event) => setPromotionDraft({ ...promotionDraft, minimum_subtotal: event.target.value })} /></label><label>Maximum redemptions<input name="maximum_redemptions" type="number" min="1" value={promotionDraft.maximum_redemptions} onChange={(event) => setPromotionDraft({ ...promotionDraft, maximum_redemptions: event.target.value })} /></label><label>Starts<input name="starts_at" type="datetime-local" value={promotionDraft.starts_at} onChange={(event) => setPromotionDraft({ ...promotionDraft, starts_at: event.target.value })} /></label><label>Ends<input name="ends_at" type="datetime-local" value={promotionDraft.ends_at} onChange={(event) => setPromotionDraft({ ...promotionDraft, ends_at: event.target.value })} /></label>
          <label>Status<select name="status" value={promotionDraft.status} onChange={(event) => setPromotionDraft({ ...promotionDraft, status: event.target.value })}><option value="draft">Draft</option><option value="active">Active</option><option value="ended">Ended</option></select></label><label className="storefront-consent-check"><input name="show_publicly" type="checkbox" checked={promotionDraft.show_publicly} onChange={(event) => setPromotionDraft({ ...promotionDraft, show_publicly: event.target.checked })} /> Show promotion publicly</label>
          <button className="button button-primary" type="submit" disabled={busy}>{selectedPromotion ? 'Save promotion' : 'Create promotion'}</button>{selectedPromotion && <button className="button button-secondary" type="button" onClick={() => { setSelectedPromotion(null); setNotice('Edit cancelled.'); }}>Cancel edit</button>}
        </form>
      </section>}

      {(sectionKey === 'social' || !sectionKey) && data?.socialLinks && permissions.includes('website.social.manage') && <section className="panel content-stack">
        <div><span className="eyebrow">PUBLIC PROFILES</span><h2>Social links</h2><p className="muted">Only HTTPS profile links are accepted. This does not publish content to social platforms.</p></div>
        <form className="website-content-form" onSubmit={(event) => void saveSocialLinks(event)}>{platforms.map((platform) => <label key={platform}>{platform[0].toUpperCase() + platform.slice(1)} URL<input type="url" maxLength={500} placeholder="https://" value={socialDraft[platform] ?? ''} onChange={(event) => setSocialDraft({ ...socialDraft, [platform]: event.target.value })} /></label>)}<button className="button button-primary" type="submit" disabled={busy}>Save social links</button></form>
      </section>}
    </div>
  )
}

function MarketingCenterPage({
  data,
  user,
  branches,
  branchScope,
  section,
  onRefresh,
}: {
  data: MarketingCenterData | null
  user: AuthUser
  branches: Branch[]
  branchScope: string
  section?: string
  onRefresh: () => void
}) {
  const seoBranches = data?.seo?.branches ?? []
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [campaignBranch, setCampaignBranch] = useState(user.branch_id ? String(user.branch_id) : '')
  const [postBranch, setPostBranch] = useState(user.branch_id ? String(user.branch_id) : '')
  const [campaign, setCampaign] = useState({
    platform: 'meta',
    name: '',
    objective: '',
    status: 'externally_managed',
    spend_amount: '',
    impressions: '',
    clicks: '',
    conversions: '',
  })
  const [campaignMetricsId, setCampaignMetricsId] = useState<number | null>(null)
  const [campaignMetrics, setCampaignMetrics] = useState({
    spend_amount: '',
    impressions: '',
    clicks: '',
    conversions: '',
  })
  const [post, setPost] = useState({ title: '', caption: '', scheduled_at: '', channels: ['instagram'] })
  const [publicationUrls, setPublicationUrls] = useState<Record<number, string>>({})
  const [keyword, setKeyword] = useState({ branch_id: '', keyword: '', target_path: '', priority: 'normal', manual_position: '' })
  const [seoBranchId, setSeoBranchId] = useState(() => (
    branchScope !== 'all' && data?.seo?.branches.some((branch) => String(branch.id) === branchScope)
      ? branchScope
      : String(data?.seo?.branches[0]?.id ?? '')
  ))
  const permissions = user.permissions
  const canManageIntegrations = permissions.includes('marketing.integrations.manage')
  const canManageCampaigns = permissions.includes('marketing.campaigns.manage')
  const canManagePosts = permissions.includes('marketing.social.manage')
  const canApprovePosts = permissions.includes('marketing.social.approve')
  const canManageSeo = permissions.includes('marketing.seo.manage')
  const canSelectOrganizationWide = permissions.includes('branch.view_all')
  const branchOptions = branches.length ? branches : seoBranches.map((branch) => ({ id: branch.id, name: branch.name, code: '' }))
  const currentSeoBranchId = branchScope !== 'all'
    && seoBranches.some((branch) => String(branch.id) === branchScope)
    ? branchScope
    : seoBranchId
  const selectedSeoBranch = seoBranches.find((branch) => String(branch.id) === currentSeoBranchId) ?? null
  const selectedCampaignForMetrics = data?.campaigns?.campaigns.find((item) => item.id === campaignMetricsId) ?? null

  function selectedBranchValue(): string {
    if (branchScope !== 'all') return branchScope
    if (canSelectOrganizationWide) return ''
    return postBranch || String(user.branch_id ?? branches[0]?.id ?? seoBranches[0]?.id ?? '')
  }

  async function connectPlatform(platform: string) {
    setBusy(true)
    setNotice('')
    try {
      const branchId = branchScope === 'all' ? null : Number(branchScope)
      const result = await apiRequest<{ authorization_url: string }>(`/marketing/integrations/${platform}/authorize`, {
        method: 'POST',
        body: JSON.stringify(branchId ? { branch_id: branchId } : {}),
      })
      window.location.assign(result.authorization_url)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
      setBusy(false)
    }
  }

  async function disconnectPlatform(connectionId: number) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/marketing/integrations/${connectionId}`, { method: 'DELETE' })
      setNotice('Provider disconnected and stored credentials cleared.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function importCampaign(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/marketing/external-campaigns', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: campaignBranch ? Number(campaignBranch) : undefined,
          platform: form.get('platform'),
          name: form.get('name'),
          objective: form.get('objective') || null,
          status: form.get('status'),
          spend_amount: form.get('spend_amount') ? Number(form.get('spend_amount')) : null,
          impressions: form.get('impressions') ? Number(form.get('impressions')) : null,
          clicks: form.get('clicks') ? Number(form.get('clicks')) : null,
          conversions: form.get('conversions') ? Number(form.get('conversions')) : null,
        }),
      })
      setCampaign({ platform: 'meta', name: '', objective: '', status: 'externally_managed', spend_amount: '', impressions: '', clicks: '', conversions: '' })
      setNotice('Campaign snapshot recorded as a manual entry; no platform sync was claimed.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  function openCampaignMetrics(item: NonNullable<MarketingCenterData['campaigns']>['campaigns'][number]) {
    setCampaignMetricsId(item.id)
    setCampaignMetrics({
      spend_amount: item.spend_amount ?? '',
      impressions: item.impressions === null ? '' : String(item.impressions),
      clicks: item.clicks === null ? '' : String(item.clicks),
      conversions: item.conversions === null ? '' : String(item.conversions),
    })
  }

  async function updateCampaignMetrics(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (campaignMetricsId === null) return

    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/marketing/external-campaigns/${campaignMetricsId}/metrics`, {
        method: 'PUT',
        body: JSON.stringify({
          spend_amount: form.get('spend_amount') ? Number(form.get('spend_amount')) : null,
          impressions: form.get('impressions') ? Number(form.get('impressions')) : null,
          clicks: form.get('clicks') ? Number(form.get('clicks')) : null,
          conversions: form.get('conversions') ? Number(form.get('conversions')) : null,
        }),
      })
      setCampaignMetricsId(null)
      setNotice('Campaign metrics updated as a manual snapshot; no platform sync was claimed.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function createPost(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const branchId = selectedBranchValue()
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/marketing/social-posts', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: branchId ? Number(branchId) : undefined,
          title: post.title,
          caption: post.caption,
          channels: post.channels,
          scheduled_at: post.scheduled_at ? new Date(post.scheduled_at).toISOString() : null,
        }),
      })
      setPost({ title: '', caption: '', scheduled_at: '', channels: ['instagram'] })
      setNotice('Social content saved as a draft. It has not been published or scheduled with a platform.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function updatePost(postId: number, action: 'submit' | 'review' | 'publication', payload?: Record<string, unknown>) {
    setBusy(true)
    setNotice('')
    try {
      const route = action === 'submit'
        ? `/marketing/social-posts/${postId}/submit`
        : action === 'review'
          ? `/marketing/social-posts/${postId}/review`
          : `/marketing/social-posts/${postId}/record-publication`
      await apiRequest(route, { method: 'POST', body: JSON.stringify(payload ?? {}) })
      setNotice(action === 'publication'
        ? 'Manual publication evidence recorded.'
        : action === 'review' ? 'Content review saved.' : 'Content submitted for approval.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveKeyword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/marketing/seo/keywords', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: form.get('branch_id') ? Number(form.get('branch_id')) : undefined,
          keyword: form.get('keyword'),
          target_path: form.get('target_path'),
          priority: form.get('priority'),
          manual_position: form.get('manual_position') ? Number(form.get('manual_position')) : null,
          position_recorded_on: form.get('manual_position') ? new Date().toISOString().slice(0, 10) : null,
        }),
      })
      setKeyword({ branch_id: '', keyword: '', target_path: '', priority: 'normal', manual_position: '' })
      setNotice('Keyword tracking entry saved. Ranking data is marked as a manual observation.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function deleteKeyword(keywordId: number) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/marketing/seo/keywords/${keywordId}`, { method: 'DELETE' })
      setNotice('Keyword removed from tracking.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveBranchSeo(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedSeoBranch) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/marketing/seo/branches/${selectedSeoBranch.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          page_title: String(form.get('page_title') ?? '') || null,
          meta_description: String(form.get('meta_description') ?? '') || null,
          target_keywords: String(form.get('target_keywords') ?? '').split(',').map((value) => value.trim()).filter(Boolean),
        }),
      })
      setNotice('Branch SEO profile saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const toggleChannel = (channel: string) => {
    setPost((current) => ({
      ...current,
      channels: current.channels.includes(channel)
        ? current.channels.filter((value) => value !== channel)
        : [...current.channels, channel],
    }))
  }

  return (
    <div className="content-stack marketing-center">
      <div className="welcome-row">
        <div><span className="eyebrow">MARKETING & GROWTH</span><h1>Marketing centre</h1><p className="muted">Manage platform access, campaign records, social content workflows, and branch search readiness.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>Refresh</button>
      </div>
      <div className="notice info">Provider connections authorize access only. Campaign import, analytics sync, social publishing, and SEO ranking sync remain manual-only until their provider adapters and permissions are verified. Never include patient names, health details, clinical photos, or treatment records in marketing content.</div>
      {notice && <div className="notice info" role="status">{notice}</div>}

      {(section === 'integrations' || !section) && data?.integrations && <section className="panel content-stack">
        <div><span className="eyebrow">PROVIDER ACCESS</span><h2>Platforms & capabilities</h2><p className="muted">A connected account does not imply that publishing or campaign synchronization is available.</p></div>
        <div className="marketing-platform-grid">{data.integrations.platforms.map((platform) => (
          <article className="marketing-platform-card" key={platform.platform}>
            <div className="welcome-row"><div><h3>{platform.name}</h3><span className={`status-pill ${platform.connection?.status === 'connected' ? 'status-active' : ''}`}>{platform.connection?.status ?? (platform.authorization === 'available' ? 'Not connected' : 'Manual only')}</span></div></div>
            {platform.connection && <p className="muted">Connected {platform.connection.connected_at ? formatDate(platform.connection.connected_at) : 'account'} · authorization only</p>}
            {platform.authorization_reason && <p className="muted">{platform.authorization_reason}</p>}
            <dl className="marketing-capability-list"><div><dt>Campaign import</dt><dd>{platform.capabilities.campaign_import}</dd></div><div><dt>Campaign metrics sync</dt><dd>{platform.capabilities.campaign_metrics_sync}</dd></div><div><dt>Campaign publishing</dt><dd>{platform.capabilities.campaign_publish}</dd></div><div><dt>Content publishing</dt><dd>{platform.capabilities.content_publish}</dd></div><div><dt>Analytics sync</dt><dd>{platform.capabilities.analytics_sync}</dd></div></dl>
            {canManageIntegrations && <div className="dialog-actions">
              {platform.authorization === 'available' && <button className="button button-primary" type="button" disabled={busy} onClick={() => void connectPlatform(platform.platform)}>{platform.connection ? 'Reconnect account' : 'Connect account'}</button>}
              {platform.connection && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void disconnectPlatform(platform.connection!.id)}>Disconnect</button>}
            </div>}
          </article>
        ))}</div>
        {data.integrations.sync_logs.length > 0 && <div className="table-scroll"><table className="data-table"><thead><tr><th>Activity</th><th>Platform</th><th>Result</th><th>When</th></tr></thead><tbody>{data.integrations.sync_logs.map((log) => <tr key={log.id}><td>{log.summary || log.operation}</td><td>{log.platform}</td><td>{log.status}{log.error_code ? ` · ${log.error_code}` : ''}</td><td>{formatDate(log.created_at)}</td></tr>)}</tbody></table></div>}
      </section>}

      {(section === 'campaigns' || !section) && data?.campaigns && <section className="panel content-stack">
        <div><span className="eyebrow">EXTERNAL CAMPAIGNS</span><h2>Campaign monitoring</h2><p className="muted">Import campaign details and metric snapshots from platform dashboards. These entries are labeled manual and are not live-synced.</p></div>
        {canManageCampaigns && <form className="website-content-form marketing-form-grid" onSubmit={(event) => void importCampaign(event)}>
          <label>Platform<select name="platform" value={campaign.platform} onChange={(event) => setCampaign({ ...campaign, platform: event.target.value })}><option value="google">Google</option><option value="meta">Meta</option><option value="tiktok">TikTok</option><option value="whatsapp">WhatsApp</option></select></label>
          <label>Campaign name<input name="name" required maxLength={180} value={campaign.name} onChange={(event) => setCampaign({ ...campaign, name: event.target.value })} /></label>
          <label>Objective<input name="objective" maxLength={120} value={campaign.objective} onChange={(event) => setCampaign({ ...campaign, objective: event.target.value })} /></label>
          <label>External status<select name="status" value={campaign.status} onChange={(event) => setCampaign({ ...campaign, status: event.target.value })}><option value="externally_managed">Externally managed</option><option value="paused">Paused</option><option value="ended">Ended</option><option value="sync_pending">Sync pending</option><option value="sync_failed">Sync failed</option></select></label>
          <label>Branch scope<select required={!canSelectOrganizationWide} value={campaignBranch || (canSelectOrganizationWide ? '' : String(user.branch_id ?? branchOptions[0]?.id ?? ''))} onChange={(event) => setCampaignBranch(event.target.value)}>{canSelectOrganizationWide && <option value="">Organization-wide</option>}{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label>Spend snapshot<input name="spend_amount" type="number" min="0" step="0.01" value={campaign.spend_amount} onChange={(event) => setCampaign({ ...campaign, spend_amount: event.target.value })} /></label>
          <label>Impressions<input name="impressions" type="number" min="0" step="1" value={campaign.impressions} onChange={(event) => setCampaign({ ...campaign, impressions: event.target.value })} /></label>
          <label>Clicks<input name="clicks" type="number" min="0" step="1" value={campaign.clicks} onChange={(event) => setCampaign({ ...campaign, clicks: event.target.value })} /></label>
          <label>Conversions<input name="conversions" type="number" min="0" step="1" value={campaign.conversions} onChange={(event) => setCampaign({ ...campaign, conversions: event.target.value })} /></label>
          <button className="button button-primary" type="submit" disabled={busy}>Record manual snapshot</button>
        </form>}
        {selectedCampaignForMetrics && <form className="website-content-form marketing-form-grid" onSubmit={(event) => void updateCampaignMetrics(event)}>
          <div className="full-width"><h3>Update metrics: {selectedCampaignForMetrics.name}</h3><p className="muted">Values are recorded as a manual observation from the provider dashboard.</p></div>
          <label>Spend<input name="spend_amount" type="number" min="0" step="0.01" value={campaignMetrics.spend_amount} onChange={(event) => setCampaignMetrics({ ...campaignMetrics, spend_amount: event.target.value })} /></label>
          <label>Impressions<input name="impressions" type="number" min="0" step="1" value={campaignMetrics.impressions} onChange={(event) => setCampaignMetrics({ ...campaignMetrics, impressions: event.target.value })} /></label>
          <label>Clicks<input name="clicks" type="number" min="0" step="1" value={campaignMetrics.clicks} onChange={(event) => setCampaignMetrics({ ...campaignMetrics, clicks: event.target.value })} /></label>
          <label>Conversions<input name="conversions" type="number" min="0" step="1" value={campaignMetrics.conversions} onChange={(event) => setCampaignMetrics({ ...campaignMetrics, conversions: event.target.value })} /></label>
          <div className="dialog-actions"><button className="button button-primary" type="submit" disabled={busy}>Save snapshot</button><button className="button button-secondary" type="button" disabled={busy} onClick={() => setCampaignMetricsId(null)}>Cancel</button></div>
        </form>}
        {data.campaigns.campaigns.length ? <div className="table-scroll"><table className="data-table"><thead><tr><th>Campaign</th><th>Platform</th><th>Status</th><th>Spend</th><th>Reach</th><th>Source</th>{canManageCampaigns && <th>Actions</th>}</tr></thead><tbody>{data.campaigns.campaigns.map((item) => <tr key={item.id}><td><strong>{item.name}</strong><small>{item.objective}</small></td><td>{item.platform}</td><td>{item.status}</td><td>{item.spend_amount === null ? '—' : `${item.currency_code} ${item.spend_amount}`}</td><td>{item.impressions ?? '—'} impressions · {item.clicks ?? '—'} clicks · {item.conversions ?? '—'} conversions<small>Metrics as of {item.metrics_recorded_at ? formatDate(item.metrics_recorded_at) : 'not recorded'}</small></td><td>{item.source === 'manual' ? 'Manual snapshot' : item.source}</td>{canManageCampaigns && <td><button className="button button-secondary" type="button" disabled={busy || item.source !== 'manual'} onClick={() => openCampaignMetrics(item)}>Update metrics</button></td>}</tr>)}</tbody></table></div> : <EmptyState compact title="No external campaigns recorded" message="Import campaign details manually or continue using the provider dashboard." />}
      </section>}

      {(section === 'social' || !section) && data?.posts && <section className="panel content-stack">
        <div><span className="eyebrow">SOCIAL CONTENT STUDIO</span><h2>Draft, review, and track content</h2><p className="muted">Approval does not publish to a network. Published externally is recorded only after a staff member adds the live HTTPS link.</p></div>
        {canManagePosts && <form className="website-content-form" onSubmit={(event) => void createPost(event)}>
          <label>Post title<input required maxLength={180} value={post.title} onChange={(event) => setPost({ ...post, title: event.target.value })} /></label>
          <label>Caption<textarea required rows={4} maxLength={5000} value={post.caption} onChange={(event) => setPost({ ...post, caption: event.target.value })} /></label>
          <fieldset className="marketing-channels"><legend>Channels</legend>{['facebook', 'instagram', 'tiktok', 'whatsapp'].map((channel) => <label key={channel}><input type="checkbox" checked={post.channels.includes(channel)} onChange={() => toggleChannel(channel)} />{channel}</label>)}</fieldset>
          <label>Planning date (not a platform schedule)<input type="datetime-local" value={post.scheduled_at} onChange={(event) => setPost({ ...post, scheduled_at: event.target.value })} /></label>
          {!canSelectOrganizationWide && <label>Branch<select required value={selectedBranchValue()} onChange={(event) => setPostBranch(event.target.value)}>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
          <button className="button button-primary" type="submit" disabled={busy || post.channels.length === 0}>Save draft</button>
        </form>}
        {data.posts.length ? <div className="marketing-post-list">{data.posts.map((item) => {
          let channels: string[]
          try {
            channels = Array.isArray(item.channels) ? item.channels : JSON.parse(item.channels) as string[]
          } catch {
            channels = []
          }
          return <article className="marketing-post-card" key={item.id}>
            <div className="welcome-row"><div><h3>{item.title}</h3><p className="muted">{channels.join(', ')}{item.scheduled_at ? ` · planned ${formatDate(item.scheduled_at)}` : ''}</p></div><span className="status-pill">{item.status.replaceAll('_', ' ')}</span></div>
            <p>{item.caption}</p>
            {item.review_note && <p className="muted">Review note: {item.review_note}</p>}
            <div className="dialog-actions">
              {canManagePosts && ['draft', 'rejected'].includes(item.status) && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void updatePost(item.id, 'submit')}>Submit for approval</button>}
              {canApprovePosts && item.status === 'pending_approval' && <><button className="button button-primary" type="button" disabled={busy} onClick={() => void updatePost(item.id, 'review', { decision: 'approve' })}>Approve</button><button className="button button-secondary" type="button" disabled={busy} onClick={() => void updatePost(item.id, 'review', { decision: 'reject', review_note: 'Please revise and resubmit.' })}>Request revision</button></>}
              {canManagePosts && item.status === 'approved' && <form className="marketing-publication-form" onSubmit={(event) => { event.preventDefault(); void updatePost(item.id, 'publication', { external_url: publicationUrls[item.id] }) }}><label className="sr-only" htmlFor={`publication-url-${item.id}`}>Live external post URL</label><input id={`publication-url-${item.id}`} type="url" required placeholder="https://…" value={publicationUrls[item.id] ?? ''} onChange={(event) => setPublicationUrls({ ...publicationUrls, [item.id]: event.target.value })} /><button className="button button-secondary" type="submit" disabled={busy}>Record as published externally</button></form>}
              {item.external_url && <a href={item.external_url} target="_blank" rel="noreferrer">View externally published post</a>}
            </div>
          </article>
        })}</div> : <EmptyState compact title="No social drafts yet" message="Create a short, approved, non-clinical post to begin the content workflow." />}
      </section>}

      {(section === 'seo' || !section) && data?.seo && <section className="panel content-stack">
        <div><span className="eyebrow">SEARCH VISIBILITY</span><h2>SEO audit & branch profiles</h2><p className="muted">{data.seo.keyword_notice}</p></div>
        <div className="metrics-grid">{[
          ['Pages audited', data.seo.summary.pages_audited],
          ['Need attention', data.seo.summary.pages_needing_attention],
          ['Tracked keywords', data.seo.summary.tracked_keywords],
          ['Branch profiles', data.seo.summary.branch_profiles_configured],
        ].map(([label, value]) => <div className="metric-card" key={label}><span>{label}</span><strong>{value}</strong></div>)}</div>
        <div className="marketing-audit-list">{data.seo.pages.map((item) => <article className="marketing-audit-card" key={item.id}><div className="welcome-row"><div><strong>{item.title}</strong><small>/{item.slug} · {item.status}</small></div><span className="status-pill">{item.audit_status === 'pass' ? 'SEO checks pass' : 'Needs attention'}</span></div>{item.issues.map((issue) => <p key={issue.code} className={issue.severity === 'high' ? 'marketing-audit-high' : 'muted'}>{issue.message}</p>)}</article>)}</div>
        {canManageSeo && <div className="marketing-seo-grid">
          <form className="website-content-form" onSubmit={(event) => void saveBranchSeo(event)}>
            <h3>Branch SEO profile</h3>
            <label>Branch<select required value={currentSeoBranchId} onChange={(event) => setSeoBranchId(event.target.value)}>{seoBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}{branch.city ? ` · ${branch.city}` : ''}</option>)}</select></label>
            <label>Search title<input name="page_title" key={`title-${currentSeoBranchId}`} maxLength={70} defaultValue={selectedSeoBranch?.seo?.page_title ?? ''} /></label>
            <label>Meta description<textarea name="meta_description" key={`description-${currentSeoBranchId}`} rows={3} maxLength={160} defaultValue={selectedSeoBranch?.seo?.meta_description ?? ''} /></label>
            <label>Target keywords (comma-separated)<input name="target_keywords" key={`keywords-${currentSeoBranchId}`} defaultValue={(selectedSeoBranch?.seo?.target_keywords ?? []).join(', ')} /></label>
            <button className="button button-primary" type="submit" disabled={busy || !selectedSeoBranch}>Save branch profile</button>
          </form>
          <form className="website-content-form" onSubmit={(event) => void saveKeyword(event)}>
            <h3>Track a keyword</h3>
            <label>Branch scope<select name="branch_id" required={!canSelectOrganizationWide} value={keyword.branch_id || (canSelectOrganizationWide ? '' : String(user.branch_id ?? seoBranches[0]?.id ?? ''))} onChange={(event) => setKeyword({ ...keyword, branch_id: event.target.value })}>{canSelectOrganizationWide && <option value="">Organization-wide</option>}{seoBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
            <label>Keyword<input name="keyword" required maxLength={180} value={keyword.keyword} onChange={(event) => setKeyword({ ...keyword, keyword: event.target.value })} /></label>
            <label>Target website path<input name="target_path" required placeholder="/services" value={keyword.target_path} onChange={(event) => setKeyword({ ...keyword, target_path: event.target.value })} /></label>
            <label>Priority<select name="priority" value={keyword.priority} onChange={(event) => setKeyword({ ...keyword, priority: event.target.value })}><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></label>
            <label>Manual ranking observation<input name="manual_position" type="number" min="1" max="1000" value={keyword.manual_position} onChange={(event) => setKeyword({ ...keyword, manual_position: event.target.value })} /></label>
            <button className="button button-primary" type="submit" disabled={busy}>Add keyword</button>
          </form>
        </div>}
        {data.seo.keywords.length > 0 && <div className="table-scroll"><table className="data-table"><thead><tr><th>Keyword</th><th>Target</th><th>Scope</th><th>Manual position</th><th>Priority</th>{canManageSeo && <th />}</tr></thead><tbody>{data.seo.keywords.map((item) => <tr key={item.id}><td>{item.keyword}</td><td>{item.target_path}</td><td>{seoBranches.find((branch) => branch.id === item.branch_id)?.name ?? 'Organization'}</td><td>{item.manual_position ? `#${item.manual_position}` : 'Not recorded'}</td><td>{item.priority}</td>{canManageSeo && <td><button className="text-button" type="button" disabled={busy} onClick={() => void deleteKeyword(item.id)}>Remove</button></td>}</tr>)}</tbody></table></div>}
      </section>}
    </div>
  )
}

function PosWorkspacePage({
  data,
  user,
  section,
}: {
  data: PosOverview | null
  user: AuthUser
  section?: string
}) {
  type CartItem = {
    item_type: 'product' | 'service'
    item_id: number
    name: string
    code: string | null
    price: number
    quantity: number
    category: string | null
    unit: string
    stock: number | null
  }
  type CustomerOption = { id: number; name: string; phone: string | null }
  type Tender = { tender_type: 'cash' | 'mpesa' | 'card'; amount: string; reference: string }

  const view = section || 'terminal'
  const [workspace, setWorkspace] = useState(data)
  const [branchId, setBranchId] = useState<number | null>(data?.branch_id ?? null)
  const [locationId, setLocationId] = useState<number | null>(data?.location_id ?? null)
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('All items')
  const [cart, setCart] = useState<CartItem[]>([])
  const [customerQuery, setCustomerQuery] = useState('')
  const [customerResults, setCustomerResults] = useState<CustomerOption[]>([])
  const [selectedCustomer, setSelectedCustomer] = useState<CustomerOption | null>(null)
  const [walkInName, setWalkInName] = useState('')
  const [discount, setDiscount] = useState('')
  const [tenders, setTenders] = useState<Tender[]>([{ tender_type: 'cash', amount: '', reference: '' }])
  const [checkoutOpen, setCheckoutOpen] = useState(false)
  const [shiftAction, setShiftAction] = useState<'open' | 'close' | 'cash' | null>(null)
  const [returnSale, setReturnSale] = useState<{ id: number; disposition: 'refund' | 'void' } | null>(null)
  const [receipt, setReceipt] = useState<PosReceipt | null>(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [pinStatus, setPinStatus] = useState<PosPinStatus | null>(null)
  const [pinSetupOpen, setPinSetupOpen] = useState(false)
  const [registers, setRegisters] = useState<PosRegister[]>([])
  const [shiftSummary, setShiftSummary] = useState<PosShiftSummary | null>(null)
  const [countedCash, setCountedCash] = useState('')
  const [historyRows, setHistoryRows] = useState<PosShiftRow[]>([])
  const [historyPage, setHistoryPage] = useState(1)
  const [historyTotal, setHistoryTotal] = useState(0)
  const [historyStatus, setHistoryStatus] = useState('')
  const [sales, setSales] = useState<{ summary: PosSalesSummary; recent: PosTransactionRow[] } | null>(null)
  const [salesFrom, setSalesFrom] = useState('')
  const [salesTo, setSalesTo] = useState('')
  const [salesLoading, setSalesLoading] = useState(false)

  async function loadPinStatus() {
    try {
      setPinStatus(await apiRequest<PosPinStatus>('/pos/pin/me'))
    } catch {
      setPinStatus(null)
    }
  }

  useEffect(() => {
    void loadPinStatus()
  }, [])

  async function loadRegisters(branch: number | null) {
    if (!branch) return
    try {
      setRegisters(await apiRequest<PosRegister[]>(`/pos/registers?branch_id=${branch}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function loadCurrentShift() {
    try {
      setShiftSummary(await apiRequest<PosShiftSummary>('/pos/shifts/current'))
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 404) {
        setShiftSummary(null)
      } else {
        setNotice(getErrorMessage(requestError))
      }
    }
  }

  async function loadShiftHistory(page = 1, status = historyStatus) {
    setBusy(true)
    setNotice('')
    try {
      const query = new URLSearchParams({ per_page: '20', page: String(page) })
      if (status) query.set('status', status)
      const result = await apiRequest<Paginated<PosShiftRow>>(`/pos/shifts?${query}`)
      setHistoryRows(result.data)
      setHistoryTotal(result.total)
      setHistoryPage(result.current_page ?? page)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadSales() {
    setSalesLoading(true)
    setNotice('')
    try {
      const query = new URLSearchParams()
      if (salesFrom) query.set('date_from', salesFrom)
      if (salesTo) query.set('date_to', salesTo)
      const result = await apiRequest<{ summary: PosSalesSummary; recent: PosTransactionRow[] }>(`/pos/sales/my?${query}`)
      setSales({ summary: result.summary, recent: result.recent ?? [] })
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setSalesLoading(false)
    }
  }

  useEffect(() => {
    if (view === 'open') void loadRegisters(branchId)
    if (view === 'current' || view === 'close') void loadCurrentShift()
    if (view === 'history') void loadShiftHistory(1)
    if (view === 'my-sales') void loadSales()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view, branchId])


  const subtotal = cart.reduce((total, item) => total + item.price * item.quantity, 0)
  const discountAmount = Math.min(Math.max(0, Number(discount) || 0), subtotal)
  const total = Math.max(0, subtotal - discountAmount)
  const tendered = tenders.reduce((sum, tender) => sum + (Number(tender.amount) || 0), 0)
  const canSell = user.permissions.includes('pos.sale.create')
  const canManageShift = user.permissions.includes('pos.shift.manage')
  const canDiscount = user.permissions.includes('pos.discount.apply')
  const canSplitTender = user.permissions.includes('pos.payment.split')
  const canRefund = user.permissions.includes('pos.refund.approve')
  const canVoid = user.permissions.includes('pos.sale.void')

  const visibleItems = useMemo(() => {
    if (!workspace) return []
    const products = workspace.products.map((product) => ({
      item_type: 'product' as const,
      item_id: product.id,
      name: product.name,
      code: product.sku,
      price: Number(product.selling_price),
      quantity: 1,
      category: product.category_name,
      unit: product.unit_of_measure,
      stock: Number(product.quantity),
    }))
    const services = workspace.services.map((service) => ({
      item_type: 'service' as const,
      item_id: service.id,
      name: service.name,
      code: null,
      price: Number(service.price),
      quantity: 1,
      category: service.category_name,
      unit: `${service.duration_minutes} min`,
      stock: null,
    }))
    return [...products, ...services].filter((item) => {
      const matchesSearch = includesSearch([item.name, item.code, item.category], search)
      const matchesCategory = category === 'All items'
        || (category === 'Services' && item.item_type === 'service')
        || (category === 'Products' && item.item_type === 'product')
        || item.category === category
      return matchesSearch && matchesCategory
    })
  }, [category, search, workspace])

  useEffect(() => {
    if (!data) return
    setWorkspace(data)
    setBranchId((current) => current ?? data.branch_id)
    setLocationId((current) => current ?? data.location_id)
  }, [data])

  async function refreshBranch(nextBranchId: number | null = branchId, nextLocationId: number | null = locationId) {
    if (!nextBranchId) return
    setBusy(true)
    setNotice('')
    try {
      const locationQuery = nextLocationId ? `&location_id=${nextLocationId}` : ''
      const result = await apiRequest<PosOverview>(`/pos/overview?branch_id=${nextBranchId}${locationQuery}`)
      setWorkspace(result)
      setBranchId(result.branch_id)
      setLocationId(result.location_id)
      setCategory('All items')
      setCustomerResults([])
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  function addToCart(item: CartItem) {
    setCart((current) => {
      const existing = current.find((line) => line.item_type === item.item_type && line.item_id === item.item_id)
      if (existing) {
        return current.map((line) => line === existing
          ? { ...line, quantity: line.quantity + 1 }
          : line)
      }
      return [...current, item]
    })
  }

  function changeQuantity(item: CartItem, delta: number) {
    setCart((current) => current.flatMap((line) => {
      if (line.item_type !== item.item_type || line.item_id !== item.item_id) return [line]
      const quantity = line.quantity + delta
      return quantity <= 0 ? [] : [{ ...line, quantity }]
    }))
  }

  async function searchCustomers() {
    if (!branchId || customerQuery.trim().length < 2) return
    setBusy(true)
    setNotice('')
    try {
      const results = await apiRequest<CustomerOption[]>(`/pos/customers?branch_id=${branchId}&search=${encodeURIComponent(customerQuery.trim())}`)
      setCustomerResults(results)
      if (!results.length) setNotice('No matching customer found at this branch. You can continue as a walk-in.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function openShift(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!branchId) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/pos/shifts', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: branchId,
          ...(form.get('register_id') ? { register_id: Number(form.get('register_id')) } : {}),
          ...(form.get('register_id') ? {} : { terminal_name: form.get('terminal_name') }),
          opening_float: Number(form.get('opening_float') ?? 0),
          pin: form.get('pin'),
        }),
      })
      setShiftAction(null)
      setNotice('Shift opened. Your PIN was verified for this shift.')
      await loadPinStatus()
      await loadCurrentShift()
      await refreshBranch(branchId)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function closeShift(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const shiftId = shiftSummary?.id ?? workspace?.shift?.id
    if (!shiftId) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ expected_cash: string; counted_cash: string; cash_variance: string }>(
        `/pos/shifts/${shiftId}/close`,
        {
          method: 'POST',
          body: JSON.stringify({
            counted_cash: Number(form.get('counted_cash')),
            staff_confirmation: form.get('staff_confirmation') === 'on',
            ...(form.get('variance_reason') ? { variance_reason: form.get('variance_reason') } : {}),
            ...(form.get('manager_pin') ? { manager_pin: form.get('manager_pin') } : {}),
          }),
        },
      )
      setNotice(`Shift closed. Expected cash KES ${Number(result.expected_cash).toFixed(2)}; variance KES ${Number(result.cash_variance).toFixed(2)}.`)
      setShiftAction(null)
      setCountedCash('')
      await loadCurrentShift()
      await refreshBranch(branchId)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function savePin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<PosPinStatus>('/pos/pin', {
        method: 'POST',
        body: JSON.stringify({
          current_password: form.get('current_password'),
          pin: form.get('pin'),
          pin_confirmation: form.get('pin_confirmation'),
        }),
      })
      setPinStatus(result)
      setPinSetupOpen(false)
      formElement.reset()
      setNotice('POS PIN saved securely. It will be required each time you open a shift.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function recordCashMovement(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const shiftId = shiftSummary?.id ?? workspace?.shift?.id
    if (!shiftId) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/pos/shifts/${shiftId}/cash-movements`, {
        method: 'POST',
        body: JSON.stringify({
          movement_type: form.get('movement_type'),
          amount: Number(form.get('amount')),
          reason: form.get('reason'),
        }),
      })
      setNotice('Till cash movement recorded.')
      setShiftAction(null)
      await loadCurrentShift()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function completeSale(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!workspace?.shift || !locationId || !branchId) return
    if (Math.round(tendered * 100) !== Math.round(total * 100)) {
      setNotice('Tender amounts must equal the order total exactly.')
      return
    }
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<PosReceipt>('/pos/sales', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: branchId,
          location_id: locationId,
          shift_id: workspace.shift.id,
          idempotency_key: crypto.randomUUID(),
          ...(selectedCustomer ? { customer_id: selectedCustomer.id } : {}),
          ...(!selectedCustomer && walkInName.trim() ? { customer_name: walkInName.trim() } : {}),
          discount_amount: discountAmount,
          lines: cart.map((item) => ({
            item_type: item.item_type,
            item_id: item.item_id,
            quantity: item.quantity,
          })),
          payments: tenders.map((tender) => ({
            tender_type: tender.tender_type,
            amount: Number(tender.amount),
            ...(tender.reference.trim() ? { reference: tender.reference.trim() } : {}),
          })),
        }),
      })
      setReceipt(result)
      setCart([])
      setDiscount('')
      setSelectedCustomer(null)
      setWalkInName('')
      setCustomerQuery('')
      setCustomerResults([])
      setTenders([{ tender_type: 'cash', amount: '', reference: '' }])
      setCheckoutOpen(false)
      await refreshBranch(branchId)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function openReceipt(saleId: number) {
    setBusy(true)
    setNotice('')
    try {
      setReceipt(await apiRequest<PosReceipt>(`/pos/sales/${saleId}/receipt`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function submitReturn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!returnSale) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ status: string; amount: string }>(
        `/pos/sales/${returnSale.id}/${returnSale.disposition}`,
        {
          method: 'POST',
          body: JSON.stringify({
            reason: form.get('reason'),
            tender_type: form.get('tender_type'),
            tender_reference: form.get('tender_reference'),
          }),
        },
      )
      setNotice(`${result.status === 'voided' ? 'Sale voided' : 'Refund recorded'} for KES ${Number(result.amount).toFixed(2)}.`)
      setReturnSale(null)
      await refreshBranch(branchId)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  function setTender(index: number, patch: Partial<Tender>) {
    setTenders((current) => current.map((tender, position) => position === index ? { ...tender, ...patch } : tender))
  }

  if (!workspace) return <LoadingState />
  const currency = (amount: number | string) => `KES ${Number(amount).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  const categories = ['All items', 'Services', 'Products', ...workspace.categories.filter((name) => !['Services', 'Products'].includes(name))]
  const canOpenShift = user.permissions.includes('pos.shift.manage')

  const pinSetupModal = pinSetupOpen && (
    <div className="pos-modal-backdrop" role="presentation">
      <section className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-pin-title">
        <div className="pos-modal-heading">
          <div><span className="eyebrow">STAFF SECURITY</span><h2 id="pos-pin-title">{pinStatus?.enrolled ? 'Change your POS PIN' : 'Set up your POS PIN'}</h2></div>
          <button className="button-icon-only" type="button" aria-label="Close PIN setup" onClick={() => setPinSetupOpen(false)}>×</button>
        </div>
        <p className="muted">Choose a unique four-digit PIN. It is stored as a one-way hash and is never shown again. You will enter it each time you open a shift.</p>
        <form onSubmit={(event) => void savePin(event)}>
          <label className="field"><span>Account password</span><input name="current_password" type="password" required autoComplete="current-password" /></label>
          <label className="field"><span>New four-digit PIN</span><input name="pin" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} minLength={4} required autoComplete="off" /></label>
          <label className="field"><span>Confirm PIN</span><input name="pin_confirmation" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} minLength={4} required autoComplete="off" /></label>
          <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setPinSetupOpen(false)}>Cancel</button><button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save PIN'}</button></div>
        </form>
      </section>
    </div>
  )

  if (view !== 'terminal') {
    return (
      <div className="content-stack">
        <div className="welcome-row module-title-row">
          <div>
            <span className="eyebrow">POINT OF SALE</span>
            <h1>{view === 'open' ? 'Open shift' : view === 'current' ? 'Current shift' : view === 'close' ? 'Close shift' : view === 'history' ? 'Shift history' : 'My sales'}</h1>
            <p className="muted">
              {view === 'open' ? 'Select an authorized branch and register, then verify your own POS PIN and opening float.'
                : view === 'current' ? 'Live till position for your open shift, including cash, mobile, card, refunds, and expected cash.'
                : view === 'close' ? 'Count the physical cash, confirm the reconciliation, and close the shift with a permanent record.'
                : view === 'history' ? 'Review your shift openings, closings, sales totals, and cash variances.'
                : 'Your own POS transactions, payment mix, refunds, and average basket.'}
            </p>
          </div>
          <button className="button button-secondary" type="button" onClick={() => { void loadPinStatus(); void refreshBranch() }} disabled={busy}>↻ Refresh</button>
        </div>

        {notice && <div className="notice success" role="status">{notice}</div>}

        {pinStatus && (!pinStatus.enrolled || pinStatus.locked) && (
          <section className={`pos-shift-bar ${pinStatus.locked ? '' : 'pos-shift-open'}`}>
            <div className="pos-shift-status">
              <span className="pos-shift-indicator" />
              <div>
                <strong>{pinStatus.locked ? 'Your POS PIN is temporarily locked' : 'Set up your POS PIN'}</strong>
                <span>{pinStatus.locked ? `Try again after ${pinStatus.locked_until ? formatDate(pinStatus.locked_until) : 'a short while'}. Contact an administrator if it stays locked.` : 'A personal four-digit PIN is required before you can open a shift.'}</span>
              </div>
            </div>
            <div className="pos-shift-actions">
              {!pinStatus.locked && <button className="button button-primary" type="button" onClick={() => setPinSetupOpen(true)}>{pinStatus.enrolled ? 'Change PIN' : 'Set up PIN'}</button>}
            </div>
          </section>
        )}

        {view === 'open' && (
          workspace.shift || shiftSummary ? (
            <section className="panel">
              <div className="panel-heading"><div><span className="eyebrow">SHIFT ALREADY OPEN</span><h2>You have an open shift</h2></div></div>
              <p className="muted">Close the current shift before opening another, or continue selling from the terminal.</p>
              <div className="pos-modal-actions"><a className="button button-secondary" href="#staff/pos/current">View current shift</a><a className="button button-primary" href="#staff/pos/terminal">Go to terminal</a></div>
            </section>
          ) : (
            <section className="panel">
              <div className="panel-heading"><div><span className="eyebrow">TILL CONTROL</span><h2>Open a new shift</h2></div><span className="pos-item-count">{pinStatus?.enrolled ? 'PIN enrolled' : 'PIN not set'}</span></div>
              <form className="form-grid" onSubmit={(event) => void openShift(event)}>
                <label className="field"><span>Branch</span>
                  <select value={branchId ?? ''} onChange={(event) => { const next = Number(event.target.value); setBranchId(next); void loadRegisters(next); void refreshBranch(next, null) }}>
                    {workspace.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                  </select>
                </label>
                <label className="field"><span>Register</span>
                  <select name="register_id" defaultValue="">
                    <option value="">No register / use terminal name</option>
                    {registers.filter((register) => register.is_active).map((register) => <option key={register.id} value={register.id}>{register.name} · {register.code}</option>)}
                  </select>
                </label>
                <label className="field"><span>Terminal name (when no register)</span><input name="terminal_name" defaultValue="Front desk" maxLength={80} /></label>
                <label className="field"><span>Opening float (KES)</span><input name="opening_float" type="number" min="0" step="0.01" defaultValue="0" required /></label>
                <label className="field"><span>Your four-digit POS PIN</span><input name="pin" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} minLength={4} required autoComplete="off" /></label>
                <div className="pos-modal-actions">
                  {!pinStatus?.enrolled && <button className="button button-secondary" type="button" onClick={() => setPinSetupOpen(true)}>Set up PIN first</button>}
                  <button className="button button-primary" type="submit" disabled={busy || !canOpenShift || !pinStatus?.enrolled || pinStatus?.locked}>
                    {busy ? 'Opening…' : 'Open shift'}
                  </button>
                </div>
              </form>
              <p className="pos-payment-disclaimer">The PIN must belong to your own account. Another employee’s PIN will be rejected. Opening a shift is audited with your user, branch, register, and float.</p>
            </section>
          )
        )}

        {view === 'current' && (
          !shiftSummary ? (
            <section className="panel">
              <EmptyState title="No open shift" message="Open a shift to start taking product and service payments." />
              {canOpenShift && <div className="pos-modal-actions"><a className="button button-primary" href="#staff/pos/open">Open shift</a></div>}
            </section>
          ) : (
            <>
              <section className="panel">
                <div className="panel-heading">
                  <div><span className="eyebrow">OPEN SHIFT</span><h2>{shiftSummary.operator_name} · {shiftSummary.register_name ?? shiftSummary.terminal_name}</h2></div>
                  <span className="pos-item-count">{shiftSummary.branch_name} · opened {formatDate(shiftSummary.opened_at)}</span>
                </div>
                <div className="metrics-grid">
                  <MetricCard label="Opening float" value={Number(shiftSummary.opening_float)} icon="◈" tone="sage" note="Cash at shift start" />
                  <MetricCard label="Sales" value={Number(shiftSummary.totals.sales_total)} icon="¤" tone="peach" note={`${shiftSummary.totals.sales_count} completed transaction(s)`} />
                  <MetricCard label="Expected cash" value={Number(shiftSummary.totals.expected_cash)} icon="▣" tone="blue" note="Float + cash in/out − cash refunds" />
                  <MetricCard label="Refunds / voids" value={Number(shiftSummary.totals.refunds_total) + Number(shiftSummary.totals.voids_total)} icon="↺" tone="lavender" note={`${shiftSummary.totals.refunds_count} refund(s), ${shiftSummary.totals.voids_count} void(s)`} />
                </div>
                <div className="table-scroll"><table><thead><tr><th>METHOD</th><th>TAKEN</th></tr></thead><tbody>
                  <tr><td>Cash</td><td>{currency(shiftSummary.totals.cash_sales)}</td></tr>
                  <tr><td>M-Pesa</td><td>{currency(shiftSummary.totals.mpesa_sales)}</td></tr>
                  <tr><td>Card</td><td>{currency(shiftSummary.totals.card_sales)}</td></tr>
                  <tr><td>Cash in</td><td>{currency(shiftSummary.totals.cash_in)}</td></tr>
                  <tr><td>Cash out</td><td>{currency(shiftSummary.totals.cash_out)}</td></tr>
                  <tr><td><strong>Expected cash</strong></td><td><strong>{currency(shiftSummary.totals.expected_cash)}</strong></td></tr>
                </tbody></table></div>
                <p className="muted">PIN verified {shiftSummary.pin_verified_at ? formatDate(shiftSummary.pin_verified_at) : '—'}.</p>
                <div className="pos-modal-actions">
                  <a className="button button-secondary" href="#staff/pos/terminal">Terminal</a>
                  <button className="button button-secondary" type="button" onClick={() => setShiftAction('cash')} disabled={!canOpenShift}>Cash movement</button>
                  <a className="button button-primary" href="#staff/pos/close">Close shift</a>
                </div>
              </section>
              {shiftAction === 'cash' && (
                <div className="pos-modal-backdrop" role="presentation">
                  <section className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-cash-title">
                    <div className="pos-modal-heading">
                      <div><span className="eyebrow">TILL CONTROL</span><h2 id="pos-cash-title">Record cash movement</h2></div>
                      <button className="button-icon-only" type="button" aria-label="Close cash dialog" onClick={() => setShiftAction(null)}>×</button>
                    </div>
                    <form onSubmit={(event) => void recordCashMovement(event)}>
                      <label className="field"><span>Movement</span><select name="movement_type"><option value="cash_in">Cash in</option><option value="cash_out">Cash out</option></select></label>
                      <label className="field"><span>Amount (KES)</span><input name="amount" type="number" min="0.01" step="0.01" required /></label>
                      <label className="field"><span>Reason</span><input name="reason" minLength={3} maxLength={500} required /></label>
                      <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setShiftAction(null)}>Cancel</button><button className="button button-primary" disabled={busy}>Record movement</button></div>
                    </form>
                  </section>
                </div>
              )}
            </>
          )
        )}

        {view === 'close' && (
          !shiftSummary ? (
            <section className="panel"><EmptyState title="No open shift to close" message="Only your currently open shift can be reconciled and closed." /></section>
          ) : (
            <section className="panel">
              <div className="panel-heading"><div><span className="eyebrow">RECONCILIATION</span><h2>Close {shiftSummary.register_name ?? shiftSummary.terminal_name}</h2></div><span className="pos-item-count">{shiftSummary.branch_name}</span></div>
              <div className="pos-checkout-due"><span>Expected cash</span><strong>{currency(shiftSummary.totals.expected_cash)}</strong></div>
              <p className="muted">Count every note and coin physically in the till drawer. A variance above the configured threshold needs a manager PIN and an explanation.</p>
              <form className="form-grid" onSubmit={(event) => void closeShift(event)}>
                <label className="field"><span>Counted cash (KES)</span><input name="counted_cash" type="number" min="0" step="0.01" required value={countedCash} onChange={(event) => setCountedCash(event.target.value)} /></label>
                <div className="field"><span>Variance</span><strong>{currency((Number(countedCash) || 0) - Number(shiftSummary.totals.expected_cash))}</strong></div>
                <label className="field"><span>Variance explanation (required when outside threshold)</span><textarea name="variance_reason" minLength={10} maxLength={1000} rows={2} /></label>
                <label className="field"><span>Manager PIN (when approval is required)</span><input name="manager_pin" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} autoComplete="off" /></label>
                <label className="field field-inline"><input type="checkbox" name="staff_confirmation" required /><span>I confirm the counted cash and reconciliation details are correct.</span></label>
                <div className="pos-modal-actions"><a className="button button-secondary" href="#staff/pos/current">Back</a><button className="button button-primary" disabled={busy}>{busy ? 'Closing…' : 'Close and reconcile'}</button></div>
              </form>
            </section>
          )
        )}

        {view === 'history' && (
          <section className="panel table-panel">
            <div className="panel-heading">
              <div><span className="eyebrow">SHIFT LEDGER</span><h2>Shift history</h2></div>
              <label className="pos-select-label"><span>Status</span>
                <select value={historyStatus} onChange={(event) => { setHistoryStatus(event.target.value); void loadShiftHistory(1, event.target.value) }}>
                  <option value="">All shifts</option>
                  <option value="open">Open</option>
                  <option value="closed">Closed</option>
                </select>
              </label>
            </div>
            {historyRows.length ? (
              <div className="table-scroll"><table><thead><tr><th>OPERATOR</th><th>BRANCH / REGISTER</th><th>OPENED</th><th>CLOSED</th><th>SALES</th><th>FLOAT</th><th>COUNTED</th><th>VARIANCE</th><th>STATUS</th></tr></thead><tbody>
                {historyRows.map((row) => (
                  <tr key={row.id}>
                    <td><strong>{row.operator_name}</strong></td>
                    <td>{row.branch_name}<small className="table-subtext">{row.register_name ?? row.terminal_name}</small></td>
                    <td>{formatDate(row.opened_at)}</td>
                    <td>{row.closed_at ? formatDate(row.closed_at) : '—'}</td>
                    <td>{currency(row.sales_total ?? 0)}<small className="table-subtext">{row.sales_count ?? 0} sale(s)</small></td>
                    <td>{currency(row.opening_float)}</td>
                    <td>{row.counted_cash === null ? '—' : currency(row.counted_cash)}</td>
                    <td>{row.cash_variance === null ? '—' : <strong className={Number(row.cash_variance) === 0 ? 'pos-zero-variance' : 'pos-nonzero-variance'}>{currency(row.cash_variance)}</strong>}</td>
                    <td><span className={`pos-status ${row.status}`}>{row.status}</span></td>
                  </tr>
                ))}
              </tbody></table></div>
            ) : <EmptyState title="No shifts found" message="Shift openings and closings will appear here." />}
            <Pagination page={historyPage} totalPages={Math.max(1, Math.ceil(historyTotal / 20))} total={historyTotal} pageSize={20} onPage={(page) => void loadShiftHistory(page)} label="Shift history pagination" />
          </section>
        )}

        {view === 'my-sales' && (
          <>
            <section className="panel">
              <form className="form-grid" onSubmit={(event) => { event.preventDefault(); void loadSales() }}>
                <label className="field"><span>From date</span><input type="date" value={salesFrom} onChange={(event) => setSalesFrom(event.target.value)} /></label>
                <label className="field"><span>To date</span><input type="date" value={salesTo} onChange={(event) => setSalesTo(event.target.value)} /></label>
                <div className="pos-modal-actions"><button className="button button-primary" type="submit" disabled={salesLoading}>{salesLoading ? 'Loading…' : 'Apply filters'}</button></div>
              </form>
            </section>
            {sales && (
              <>
                <div className="metrics-grid">
                  <MetricCard label="Gross sales" value={Number(sales.summary.gross_sales)} icon="¤" tone="sage" note={`${sales.summary.transaction_count} transaction(s)`} />
                  <MetricCard label="Net sales" value={Number(sales.summary.net_sales)} icon="▣" tone="peach" note="Gross less refunds" />
                  <MetricCard label="Cash collected" value={Number(sales.summary.cash_collected)} icon="◈" tone="blue" note="Cash taken less cash refunds" />
                  <MetricCard label="Average basket" value={Number(sales.summary.average_transaction_value)} icon="✳" tone="lavender" note="Net sales / transactions" />
                </div>
                <section className="panel">
                  <div className="panel-heading"><div><span className="eyebrow">PAYMENT MIX</span><h2>How customers paid</h2></div></div>
                  <div className="table-scroll"><table><thead><tr><th>METHOD</th><th>PAYMENTS</th><th>REFUNDS</th><th>PRODUCT SALES</th><th>SERVICE SALES</th><th>DISCOUNTS</th><th>VOIDS</th></tr></thead><tbody>
                    <tr><td>Cash</td><td>{currency(sales.summary.payments.cash)}</td><td>{currency(sales.summary.refunds_by_method.cash)}</td><td colSpan={4} /></tr>
                    <tr><td>M-Pesa</td><td>{currency(sales.summary.payments.mpesa)}</td><td>{currency(sales.summary.refunds_by_method.mpesa)}</td><td colSpan={4} /></tr>
                    <tr><td>Card</td><td>{currency(sales.summary.payments.card)}</td><td>{currency(sales.summary.refunds_by_method.card)}</td><td colSpan={4} /></tr>
                    <tr><td><strong>Totals</strong></td><td><strong>{currency(Number(sales.summary.payments.cash) + Number(sales.summary.payments.mpesa) + Number(sales.summary.payments.card))}</strong></td><td><strong>{currency(sales.summary.refunds)}</strong></td><td>{currency(sales.summary.product_sales)}</td><td>{currency(sales.summary.service_sales)}</td><td>{currency(sales.summary.discounts)}</td><td>{currency(sales.summary.voids)}</td></tr>
                  </tbody></table></div>
                  <p className="muted">Outstanding balance: {currency(sales.summary.outstanding)}. Only completed and refunded sales count toward revenue; voids are reported separately.</p>
                </section>
                <section className="panel table-panel">
                  <div className="panel-heading"><div><span className="eyebrow">MY TRANSACTIONS</span><h2>Recent POS sales</h2></div><span className="pos-item-count">Latest 50 in range</span></div>
                  {sales.recent.length ? (
                    <div className="table-scroll"><table><thead><tr><th>RECEIPT</th><th>DATE</th><th>CUSTOMER</th><th>PAYMENT</th><th>STATUS</th><th>TOTAL</th><th>REFUNDED</th></tr></thead><tbody>
                      {sales.recent.map((row) => (
                        <tr key={row.id}>
                          <td><strong>{row.receipt_number}</strong></td>
                          <td>{formatDate(row.sold_at)}</td>
                          <td>{row.customer_name ?? 'Walk-in'}</td>
                          <td>{row.payment_methods.length ? row.payment_methods.join(', ') : '—'}</td>
                          <td><span className={`pos-status ${row.status}`}>{row.status}</span></td>
                          <td>{currency(row.total_amount)}</td>
                          <td>{currency(row.refunded_amount)}</td>
                        </tr>
                      ))}
                    </tbody></table></div>
                  ) : <EmptyState title="No sales in this period" message="Completed product and service checkouts will appear here." />}
                </section>
              </>
            )}
          </>
        )}

        {pinSetupModal}
      </div>
    )
  }

  return (
    <div className="content-stack pos-workspace">
      <div className="welcome-row module-title-row pos-heading">
        <div>
          <span className="eyebrow">CLINIC CHECKOUT</span>
          <h1>Point of sale</h1>
          <p className="muted">Retail products and clinic services, together in one clear checkout.</p>
        </div>
        <div className="pos-heading-actions">
          <label className="pos-select-label">
            <span>Branch</span>
            <select
              aria-label="POS branch"
              value={branchId ?? ''}
              onChange={(event) => void refreshBranch(Number(event.target.value), null)}
              disabled={busy}
            >
              {workspace.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
            </select>
          </label>
          <label className="pos-select-label">
            <span>Stock location</span>
            <select
              aria-label="POS stock location"
              value={locationId ?? ''}
              onChange={(event) => void refreshBranch(branchId, Number(event.target.value))}
              disabled={!workspace.locations.length}
            >
              {workspace.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}
            </select>
          </label>
          <button className="button button-secondary" type="button" onClick={() => void refreshBranch()} disabled={busy}>↻ Refresh</button>
        </div>
      </div>

      {notice && <div className="notice success" role="status">{notice}</div>}

      <div className="metrics-grid pos-metrics">
        <MetricCard label="Today’s sales" value={Number(workspace.metrics.sales_total)} icon="¤" tone="sage" note="Completed sales" />
        <MetricCard label="Transactions" value={workspace.metrics.sales_count} icon="▣" tone="peach" note="Today at this branch" />
        <MetricCard label="Customers served" value={workspace.metrics.customers_count} icon="♡" tone="lavender" note="Registered customers" />
        <MetricCard label="Current order" value={Number(total.toFixed(2))} icon="＋" tone="blue" note="KES · before tax setup" />
      </div>

      <section className={`pos-shift-bar ${workspace.shift ? 'pos-shift-open' : ''}`}>
        <div className="pos-shift-status">
          <span className="pos-shift-indicator" />
          <div>
            <strong>{workspace.shift ? 'Till shift is open' : 'No till shift open'}</strong>
            <span>{workspace.shift ? `${workspace.shift.terminal_name} · opened ${formatDate(workspace.shift.opened_at)}` : 'Open a shift and enter the opening float before taking payment.'}</span>
          </div>
        </div>
        <div className="pos-shift-actions">
          {workspace.shift && canManageShift && <>
            <button className="button button-secondary" type="button" onClick={() => setShiftAction('cash')}>Cash movement</button>
            <a className="button button-secondary" href="#staff/pos/close">Close shift</a>
          </>}
          {!workspace.shift && canManageShift && <a className="button button-primary" href="#staff/pos/open">＋ Open shift</a>}
          <button className="button button-secondary" type="button" onClick={() => setPinSetupOpen(true)} disabled={!pinStatus?.eligible}>
            {pinStatus?.enrolled ? 'Change POS PIN' : 'Set up POS PIN'}
          </button>
        </div>
      </section>

      {pinStatus && (!pinStatus.enrolled || pinStatus.locked) && (
        <div className={`notice ${pinStatus.locked ? 'error' : 'success'}`} role="status">
          {pinStatus.locked
            ? `Your POS PIN is locked until ${pinStatus.locked_until ? formatDate(pinStatus.locked_until) : 'further notice'}. Ask a POS administrator to unlock it.`
            : 'Set up your personal four-digit POS PIN before opening a shift. Another employee’s PIN will not work.'}
        </div>
      )}

      {!workspace.locations.length && <div className="notice error" role="alert">This branch has no active stock location. Add one in Inventory before starting checkout.</div>}

      <div className="pos-layout">
        <section className="pos-catalog panel">
          <div className="pos-catalog-heading">
            <div><span className="eyebrow">CATALOGUE</span><h2>Find an item</h2></div>
            <span className="pos-item-count">{visibleItems.length} available</span>
          </div>
          <div className="pos-category-tabs" role="tablist" aria-label="Catalog categories">
            {categories.map((name) => (
              <button
                type="button"
                role="tab"
                aria-selected={category === name}
                className={`pos-category-tab ${category === name ? 'active' : ''}`}
                key={name}
                onClick={() => setCategory(name)}
              >
                {name}
              </button>
            ))}
          </div>
          <div className="pos-search-row">
            <label className="pos-search">
              <span aria-hidden="true">⌕</span>
              <span className="sr-only">Search products and services</span>
              <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, SKU or scan barcode…" />
              {search && <button type="button" aria-label="Clear search" onClick={() => setSearch('')}>×</button>}
            </label>
            <span className="pos-scan-hint">Barcode scanner ready</span>
          </div>
          {visibleItems.length ? (
            <div className="pos-product-grid">
              {visibleItems.map((item) => (
                <button
                  type="button"
                  className="pos-product-card"
                  key={`${item.item_type}-${item.item_id}`}
                  onClick={() => addToCart(item)}
                  disabled={!canSell || (item.stock !== null && item.stock <= 0)}
                >
                  <span className={`pos-product-art ${item.item_type === 'service' ? 'service-art' : ''}`} aria-hidden="true">
                    <span>{item.item_type === 'service' ? '✳' : '◈'}</span>
                    <small>{item.item_type === 'service' ? 'OSHEL CARE' : 'OSHEL SKIN'}</small>
                  </span>
                  <span className="pos-product-title">{item.name}</span>
                  <span className="pos-product-meta">{item.category ?? (item.item_type === 'service' ? 'Clinic service' : item.code)}</span>
                  <span className="pos-product-footer">
                    <strong>{currency(item.price)}</strong>
                    {item.stock !== null
                      ? <small>{item.stock} {item.unit} left</small>
                      : <small>{item.unit}</small>}
                  </span>
                  <span className="pos-product-add" aria-hidden="true">＋</span>
                </button>
              ))}
            </div>
          ) : (
            <EmptyState title="Nothing matches this search" message="Try another product, service, category, or barcode." />
          )}
        </section>

        <aside className="pos-order panel" aria-label="Current order">
          <div className="pos-order-heading">
            <div><span className="eyebrow">CHECKOUT</span><h2>Current order</h2></div>
            <button className="text-button" type="button" disabled={!cart.length} onClick={() => { setCart([]); setDiscount('') }}>Clear</button>
          </div>
          <div className="pos-order-branch">{workspace.branches.find((branch) => branch.id === branchId)?.name ?? 'Select a branch'}</div>
          {cart.length ? (
            <div className="pos-cart-list">
              {cart.map((item) => (
                <div className="pos-cart-item" key={`${item.item_type}-${item.item_id}`}>
                  <span className={`pos-cart-icon ${item.item_type === 'service' ? 'service-art' : ''}`}>{item.item_type === 'service' ? '✳' : '◈'}</span>
                  <div className="pos-cart-copy">
                    <strong>{item.name}</strong>
                    <small>{currency(item.price)} · {item.item_type === 'service' ? 'Service' : item.code}</small>
                    <div className="pos-quantity-control">
                      <button type="button" aria-label={`Decrease ${item.name}`} onClick={() => changeQuantity(item, -1)}>−</button>
                      <span>{item.quantity}</span>
                      <button
                        type="button"
                        aria-label={`Increase ${item.name}`}
                        disabled={item.stock !== null && item.quantity >= item.stock}
                        onClick={() => changeQuantity(item, 1)}
                      >＋</button>
                    </div>
                  </div>
                  <strong className="pos-line-total">{currency(item.price * item.quantity)}</strong>
                  <button className="pos-remove" type="button" aria-label={`Remove ${item.name}`} onClick={() => changeQuantity(item, -item.quantity)}>×</button>
                </div>
              ))}
            </div>
          ) : (
            <div className="pos-cart-empty"><span aria-hidden="true">◈</span><strong>Your order is waiting</strong><small>Add a product or service to begin.</small></div>
          )}

          <div className="pos-customer-box">
            <label htmlFor="pos-customer-search">Customer</label>
            {selectedCustomer ? (
              <div className="pos-selected-customer">
                <span><strong>{selectedCustomer.name}</strong><small>{selectedCustomer.phone ?? 'Registered customer'}</small></span>
                <button type="button" aria-label="Remove selected customer" onClick={() => setSelectedCustomer(null)}>×</button>
              </div>
            ) : (
              <>
                <div className="pos-customer-search">
                  <input id="pos-customer-search" value={customerQuery} onChange={(event) => setCustomerQuery(event.target.value)} placeholder="Search registered customers…" />
                  <button className="button button-secondary" type="button" onClick={() => void searchCustomers()} disabled={busy || customerQuery.trim().length < 2}>Find</button>
                </div>
                {customerResults.length > 0 && (
                  <div className="pos-customer-results">
                    {customerResults.map((customer) => (
                      <button key={customer.id} type="button" onClick={() => { setSelectedCustomer(customer); setCustomerResults([]); setWalkInName('') }}>
                        <strong>{customer.name}</strong><small>{customer.phone ?? 'No phone on file'}</small>
                      </button>
                    ))}
                  </div>
                )}
                <input className="pos-walk-in-name" value={walkInName} onChange={(event) => setWalkInName(event.target.value)} placeholder="Or enter walk-in name (optional)" />
              </>
            )}
          </div>

          <div className="pos-totals">
            <div><span>Subtotal</span><strong>{currency(subtotal)}</strong></div>
            {canDiscount && (
              <label className="pos-discount-field"><span>Authorized discount</span><input type="number" min="0" max={subtotal} step="0.01" value={discount} onChange={(event) => setDiscount(event.target.value)} placeholder="0.00" /></label>
            )}
            {discountAmount > 0 && <div className="pos-discount-total"><span>Discount</span><strong>− {currency(discountAmount)}</strong></div>}
            <div className="pos-tax-note"><span>Tax</span><small>Not configured</small></div>
            <div className="pos-grand-total"><span>Total</span><strong>{currency(total)}</strong></div>
          </div>
          <button
            className="button button-primary pos-charge-button"
            type="button"
            disabled={!canSell || !workspace.shift || !cart.length || !locationId || busy}
            onClick={() => { setNotice(''); setCheckoutOpen(true) }}
          >
            Charge {currency(total)} <span aria-hidden="true">→</span>
          </button>
          {!canSell && <p className="pos-readonly-note">This account can view sales but cannot create or charge an order.</p>}
          {canSell && !workspace.shift && <p className="pos-readonly-note">Open a till shift to enable checkout.</p>}
        </aside>
      </div>

      <section className="panel table-panel pos-sales-panel">
        <div className="panel-heading">
          <div><span className="eyebrow">BRANCH ACTIVITY</span><h2>Recent sales</h2></div>
          <span className="pos-item-count">Receipts can be reopened as a marked copy</span>
        </div>
        {workspace.recent_sales.length ? (
          <div className="table-scroll"><table><thead><tr><th>RECEIPT</th><th>CUSTOMER</th><th>TIME</th><th>STATUS</th><th>TOTAL</th><th>ACTIONS</th></tr></thead><tbody>
            {workspace.recent_sales.map((sale) => (
              <tr key={sale.id}>
                <td><strong>{sale.receipt_number}</strong></td>
                <td>{sale.customer_name_snapshot ?? 'Walk-in'}</td>
                <td>{formatDate(sale.sold_at)}</td>
                <td><span className={`pos-status ${sale.status}`}>{sale.status}</span></td>
                <td>{currency(sale.total_amount)}</td>
                <td className="pos-sale-actions">
                  <button className="text-button" type="button" disabled={busy} onClick={() => void openReceipt(sale.id)}>Receipt</button>
                  <button className="text-button" type="button" disabled={busy} onClick={() => void apiDownload(`/documents/pos-sales/${sale.id}/receipt`, `oshel-receipt-${sale.receipt_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>PDF</button>
                  {sale.status === 'completed' && canRefund && <button className="text-button" type="button" onClick={() => setReturnSale({ id: sale.id, disposition: 'refund' })}>Refund</button>}
                  {sale.status === 'completed' && canVoid && <button className="text-button" type="button" onClick={() => setReturnSale({ id: sale.id, disposition: 'void' })}>Void</button>}
                </td>
              </tr>
            ))}
          </tbody></table></div>
        ) : <EmptyState title="No sales yet today" message="Completed product and service checkouts will appear here." />}
      </section>

      <section className="panel table-panel pos-sales-panel">
        <div className="panel-heading">
          <div><span className="eyebrow">CASH CONTROL</span><h2>Recent till shifts</h2></div>
          <span className="pos-item-count">Closed shifts include the counted cash variance</span>
        </div>
        {workspace.recent_shifts.length ? (
          <div className="table-scroll"><table><thead><tr><th>TERMINAL / CASHIER</th><th>OPENED</th><th>STATUS</th><th>EXPECTED</th><th>COUNTED</th><th>VARIANCE</th></tr></thead><tbody>
            {workspace.recent_shifts.map((shift) => (
              <tr key={shift.id}>
                <td><strong>{shift.terminal_name}</strong><small className="table-subtext">{shift.cashier_name}</small></td>
                <td>{formatDate(shift.opened_at)}</td>
                <td><span className={`pos-status ${shift.status}`}>{shift.status}</span></td>
                <td>{shift.expected_cash === null ? '—' : currency(shift.expected_cash)}</td>
                <td>{shift.counted_cash === null ? '—' : currency(shift.counted_cash)}</td>
                <td>{shift.cash_variance === null ? '—' : <strong className={Number(shift.cash_variance) === 0 ? 'pos-zero-variance' : 'pos-nonzero-variance'}>{currency(shift.cash_variance)}</strong>}</td>
              </tr>
            ))}
          </tbody></table></div>
        ) : <EmptyState title="No till shifts recorded" message="Your branch’s till opening and reconciliation history will appear here." />}
      </section>

      {checkoutOpen && (
        <div className="pos-modal-backdrop" role="presentation">
          <section className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-checkout-title">
            <div className="pos-modal-heading">
              <div><span className="eyebrow">PAYMENT</span><h2 id="pos-checkout-title">Complete checkout</h2></div>
              <button className="button-icon-only" type="button" aria-label="Close checkout" onClick={() => setCheckoutOpen(false)}>×</button>
            </div>
            <div className="pos-checkout-due"><span>Amount due</span><strong>{currency(total)}</strong></div>
            <form onSubmit={(event) => void completeSale(event)}>
              <div className="pos-payment-list">
                {tenders.map((tender, index) => (
                  <div className="pos-payment-row" key={index}>
                    <label><span>Tender {index + 1}</span><select value={tender.tender_type} onChange={(event) => setTender(index, { tender_type: event.target.value as Tender['tender_type'] })}>
                      <option value="cash">Cash</option><option value="mpesa">M-Pesa (manual reference)</option><option value="card">Card (manual reference)</option>
                    </select></label>
                    <label><span>Amount (KES)</span><input type="number" min="0.01" step="0.01" required value={tender.amount} onChange={(event) => setTender(index, { amount: event.target.value })} /></label>
                    {tender.tender_type !== 'cash' && <label className="pos-reference-field"><span>{tender.tender_type === 'mpesa' ? 'M-Pesa reference' : 'Card terminal reference'}</span><input value={tender.reference} onChange={(event) => setTender(index, { reference: event.target.value })} required /></label>}
                    {tenders.length > 1 && <button className="pos-remove" type="button" aria-label={`Remove tender ${index + 1}`} onClick={() => setTenders((current) => current.filter((_, position) => position !== index))}>×</button>}
                  </div>
                ))}
              </div>
              {canSplitTender && tenders.length < 3 && <button className="text-button pos-add-tender" type="button" onClick={() => setTenders((current) => [...current, { tender_type: 'mpesa', amount: '', reference: '' }])}>＋ Add another tender</button>}
              <div className={`pos-payment-balance ${Math.round(tendered * 100) === Math.round(total * 100) ? 'balanced' : ''}`}>
                <span>{tendered > total ? 'Over by' : 'Remaining'}</span>
                <strong>{currency(Math.abs(total - tendered))}</strong>
              </div>
              <p className="pos-payment-disclaimer">Payments are recorded against the till. M-Pesa and card references are entered manually; no provider settlement is verified by this screen.</p>
              {notice && <div className="notice error" role="alert">{notice}</div>}
              <div className="pos-modal-actions">
                <button className="button button-secondary" type="button" onClick={() => setCheckoutOpen(false)}>Back to order</button>
                <button className="button button-primary" type="submit" disabled={busy || Math.round(tendered * 100) !== Math.round(total * 100)}>{busy ? 'Recording…' : `Complete sale · ${currency(total)}`}</button>
              </div>
            </form>
          </section>
        </div>
      )}

      {shiftAction && (
        <div className="pos-modal-backdrop" role="presentation">
          <section className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-shift-title">
            <div className="pos-modal-heading">
              <div><span className="eyebrow">TILL CONTROL</span><h2 id="pos-shift-title">{shiftAction === 'open' ? 'Open till shift' : shiftAction === 'close' ? 'Reconcile and close shift' : 'Record cash movement'}</h2></div>
              <button className="button-icon-only" type="button" aria-label="Close till dialog" onClick={() => setShiftAction(null)}>×</button>
            </div>
            {shiftAction === 'open' ? (
              <form onSubmit={(event) => void openShift(event)}>
                <label className="field"><span>Terminal name</span><input name="terminal_name" defaultValue="Front desk" required maxLength={80} /></label>
                <label className="field"><span>Opening float (KES)</span><input name="opening_float" type="number" min="0" step="0.01" defaultValue="0" required /></label>
                <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setShiftAction(null)}>Cancel</button><button className="button button-primary" disabled={busy}>Open shift</button></div>
              </form>
            ) : shiftAction === 'close' ? (
              <form onSubmit={(event) => void closeShift(event)}>
                <p className="muted">Count the cash physically in the till. The system compares it to the opening float, cash sales/refunds, and recorded cash movements.</p>
                <label className="field"><span>Counted cash (KES)</span><input name="counted_cash" type="number" min="0" step="0.01" required autoFocus /></label>
                <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setShiftAction(null)}>Cancel</button><button className="button button-primary" disabled={busy}>Close and reconcile</button></div>
              </form>
            ) : (
              <form onSubmit={(event) => void recordCashMovement(event)}>
                <label className="field"><span>Movement</span><select name="movement_type"><option value="cash_in">Cash in</option><option value="cash_out">Cash out</option></select></label>
                <label className="field"><span>Amount (KES)</span><input name="amount" type="number" min="0.01" step="0.01" required /></label>
                <label className="field"><span>Reason</span><input name="reason" minLength={3} maxLength={500} required /></label>
                <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setShiftAction(null)}>Cancel</button><button className="button button-primary" disabled={busy}>Record movement</button></div>
              </form>
            )}
          </section>
        </div>
      )}

      {returnSale && (
        <div className="pos-modal-backdrop" role="presentation">
          <section className="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pos-return-title">
            <div className="pos-modal-heading">
              <div><span className="eyebrow">MANAGER APPROVAL</span><h2 id="pos-return-title">{returnSale.disposition === 'void' ? 'Void completed sale' : 'Refund completed sale'}</h2></div>
              <button className="button-icon-only" type="button" aria-label="Close return dialog" onClick={() => setReturnSale(null)}>×</button>
            </div>
            <p className="muted">This records a full reversal, restores the original product batches, and keeps the original receipt in the ledger.</p>
            <form onSubmit={(event) => void submitReturn(event)}>
              <label className="field"><span>Reason</span><textarea name="reason" minLength={3} maxLength={1000} required rows={3} /></label>
              <label className="field"><span>Refund tender</span><select name="tender_type"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option></select></label>
              <label className="field"><span>Refund reference (required for M-Pesa/card)</span><input name="tender_reference" maxLength={120} /></label>
              <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setReturnSale(null)}>Cancel</button><button className="button button-primary" disabled={busy}>{returnSale.disposition === 'void' ? 'Approve void' : 'Approve refund'}</button></div>
            </form>
          </section>
        </div>
      )}

      {receipt && (
        <div className="pos-modal-backdrop pos-receipt-overlay" role="presentation">
          <section className="pos-receipt-dialog" role="dialog" aria-modal="true" aria-label="Sale receipt">
            <article className="pos-receipt-paper">
              <div className="pos-receipt-brand">oshel<span>.</span></div>
              <p>{receipt.branch_name} · {receipt.branch_code}</p>
              <strong className="pos-receipt-copy">{receipt.copy ? 'COPY — REPRINT' : receipt.replayed ? 'COPY — CHECKOUT RETRY' : 'CUSTOMER RECEIPT'}</strong>
              <div className="pos-receipt-rule" />
              <div className="pos-receipt-meta"><span>Receipt</span><strong>{receipt.receipt_number}</strong></div>
              <div className="pos-receipt-meta"><span>Date</span><strong>{formatDate(receipt.sold_at)}</strong></div>
              <div className="pos-receipt-meta"><span>Cashier</span><strong>{receipt.cashier_name}</strong></div>
              <div className="pos-receipt-meta"><span>Customer</span><strong>{receipt.customer_name_snapshot ?? 'Walk-in'}</strong></div>
              <div className="pos-receipt-rule" />
              {receipt.lines?.map((line, index) => (
                <div className="pos-receipt-line" key={`${line.item_name}-${index}`}>
                  <span>{line.item_name}<small>{line.quantity} × {currency(line.unit_price)}</small></span><strong>{currency(line.line_total)}</strong>
                </div>
              ))}
              <div className="pos-receipt-rule" />
              <div className="pos-receipt-meta"><span>Subtotal</span><strong>{currency(receipt.subtotal)}</strong></div>
              {Number(receipt.discount_amount) > 0 && <div className="pos-receipt-meta"><span>Discount</span><strong>− {currency(receipt.discount_amount)}</strong></div>}
              <div className="pos-receipt-meta"><span>Tax</span><strong>{currency(receipt.tax_amount)} · not configured</strong></div>
              <div className="pos-receipt-meta pos-receipt-total"><span>Total paid</span><strong>{currency(receipt.total_amount)}</strong></div>
              {receipt.payments?.map((payment, index) => <div className="pos-receipt-meta" key={`${payment.tender_type}-${index}`}><span>{payment.tender_type.toUpperCase()} {payment.reference ? `· ${payment.reference}` : ''}</span><strong>{currency(payment.amount)}</strong></div>)}
              {receipt.status !== 'completed' && <div className="pos-receipt-return">{receipt.status.toUpperCase()} · original sale retained</div>}
              <div className="pos-receipt-rule" />
              <p className="pos-receipt-thanks">Thank you for choosing Oshel.</p>
            </article>
            <div className="pos-modal-actions pos-receipt-actions">
              <button className="button button-secondary" type="button" onClick={() => setReceipt(null)}>Close</button>
              <button className="button button-secondary" type="button" onClick={() => void apiDownload(`/documents/pos-sales/${receipt.id}/receipt`, `oshel-receipt-${receipt.receipt_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>Download PDF</button>
              <button className="button button-primary" type="button" onClick={() => window.print()}>Print receipt</button>
            </div>
          </section>
        </div>
      )}

      {pinSetupModal}
    </div>
  )
}

function PosManagementPage({
  user,
  section,
}: {
  user: AuthUser
  section?: string
}) {
  const view = section || 'shifts'
  const [branches, setBranches] = useState<Branch[]>([])
  const [staffOptions, setStaffOptions] = useState<{ id: number; name: string }[]>([])
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [branchFilter, setBranchFilter] = useState('')
  const [staffFilter, setStaffFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [shiftFilter, setShiftFilter] = useState('')
  const [itemType, setItemType] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('')
  const [varianceOnly, setVarianceOnly] = useState(false)
  const [shifts, setShifts] = useState<Paginated<PosShiftRow> | null>(null)
  const [activeShifts, setActiveShifts] = useState<PosShiftRow[]>([])
  const [transactions, setTransactions] = useState<Paginated<PosTransactionRow> | null>(null)
  const [returns, setReturns] = useState<Paginated<PosReturnRow> | null>(null)
  const [report, setReport] = useState<PosSalesReport | null>(null)
  const [registers, setRegisters] = useState<PosRegister[]>([])
  const [registerBranch, setRegisterBranch] = useState<number | ''>('')
  const [settings, setSettings] = useState<PosSettingsRecord | null>(null)
  const [pins, setPins] = useState<PosPinStaff[]>([])
  const [pinAudit, setPinAudit] = useState<PosPinAuditRow[]>([])
  const [selectedShift, setSelectedShift] = useState<PosShiftDetail | null>(null)
  const [adjustAction, setAdjustAction] = useState<'adjust' | 'reopen' | null>(null)

  const money = (amount: number | string) => `KES ${Number(amount).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

  function filterQuery(includeStatus = true): URLSearchParams {
    const query = new URLSearchParams()
    if (dateFrom) query.set('date_from', dateFrom)
    if (dateTo) query.set('date_to', dateTo)
    if (branchFilter) query.set('branch_id', branchFilter)
    if (staffFilter) query.set('staff_id', staffFilter)
    if (includeStatus && statusFilter) query.set('status', statusFilter)
    if (shiftFilter) query.set('shift_id', shiftFilter)
    if (itemType) query.set('item_type', itemType)
    if (paymentMethod) query.set('payment_method', paymentMethod)
    return query
  }

  async function loadBranches() {
    try {
      setBranches(await apiRequest<Branch[]>('/branches'))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function loadStaffOptions() {
    try {
      setStaffOptions(await apiRequest<{ id: number; name: string }[]>('/pos/staff'))
    } catch {
      setStaffOptions([])
    }
  }

  async function loadShifts(page = 1) {
    setBusy(true)
    setNotice('')
    try {
      const query = filterQuery()
      query.set('per_page', '20')
      query.set('page', String(page))
      if (varianceOnly) query.set('variance_only', '1')
      setShifts(await apiRequest<Paginated<PosShiftRow>>(`/pos/shifts?${query}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadActiveShifts() {
    setBusy(true)
    setNotice('')
    try {
      const rows = await apiRequest<PosShiftRow[]>('/pos/shifts/active')
      setActiveShifts(rows.map((row) => ({ ...row, sales_total: row.sales_total ?? row.totals?.sales_total, sales_count: row.sales_count ?? row.totals?.sales_count })))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadTransactions(page = 1) {
    setBusy(true)
    setNotice('')
    try {
      const query = filterQuery()
      query.set('per_page', '25')
      query.set('page', String(page))
      setTransactions(await apiRequest<Paginated<PosTransactionRow>>(`/pos/sales/transactions?${query}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadReturns(page = 1) {
    setBusy(true)
    setNotice('')
    try {
      const query = new URLSearchParams({ per_page: '25', page: String(page) })
      if (dateFrom) query.set('date_from', dateFrom)
      if (dateTo) query.set('date_to', dateTo)
      if (staffFilter) query.set('staff_id', staffFilter)
      setReturns(await apiRequest<Paginated<PosReturnRow>>(`/pos/sales/returns?${query}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadReport() {
    setBusy(true)
    setNotice('')
    try {
      setReport(await apiRequest<PosSalesReport>(`/pos/sales/report?${filterQuery()}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadRegisters(branch: number | '') {
    if (!branch) {
      setRegisters([])
      return
    }
    setBusy(true)
    setNotice('')
    try {
      setRegisters(await apiRequest<PosRegister[]>(`/pos/registers?branch_id=${branch}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadSettings() {
    setBusy(true)
    setNotice('')
    try {
      setSettings(await apiRequest<PosSettingsRecord>('/pos/settings'))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadPins() {
    setBusy(true)
    setNotice('')
    try {
      const [rows, audit] = await Promise.all([
        apiRequest<PosPinStaff[]>('/pos/pins'),
        apiRequest<PosPinAuditRow[]>('/pos/pins/audit'),
      ])
      setPins(rows)
      setPinAudit(audit)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadActiveView() {
    if (view === 'shifts') await loadShifts(1)
    else if (view === 'active') await loadActiveShifts()
    else if (view === 'staff-sales') await loadReport()
    else if (view === 'transactions') await loadTransactions(1)
    else if (view === 'returns') await loadReturns(1)
    else if (view === 'registers') await loadRegisters(registerBranch)
    else if (view === 'settings') await loadSettings()
    else if (view === 'pins') await loadPins()
  }

  useEffect(() => {
    void loadBranches()
    if (user.permissions.includes('pos.sales.view_all') || user.permissions.includes('pos.shift.view_all')) void loadStaffOptions()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    setNotice('')
    setSelectedShift(null)
    void loadActiveView()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view])

  useEffect(() => {
    if (registerBranch === '' && branches.length) setRegisterBranch(branches[0].id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branches])

  useEffect(() => {
    if (view === 'registers' && registerBranch !== '') void loadRegisters(registerBranch)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [registerBranch])

  async function openShiftDetail(id: number) {
    setBusy(true)
    setNotice('')
    try {
      setSelectedShift(await apiRequest<PosShiftDetail>(`/pos/shifts/${id}`))
      setAdjustAction(null)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function submitShiftAdjustment(event: FormEvent<HTMLFormElement>, action: 'adjust' | 'reopen') {
    event.preventDefault()
    if (!selectedShift) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/pos/shifts/${selectedShift.id}/${action}`, {
        method: 'POST',
        body: JSON.stringify({
          reason: form.get('reason'),
          ...(action === 'adjust'
            ? {
                counted_cash: Number(form.get('counted_cash')),
                ...(form.get('variance_reason') ? { variance_reason: form.get('variance_reason') } : {}),
              }
            : {}),
        }),
      })
      setNotice(action === 'adjust' ? 'Shift adjustment recorded with an immutable audit trail.' : 'Shift reopened under manager authority.')
      setAdjustAction(null)
      await openShiftDetail(selectedShift.id)
      await loadActiveView()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function createRegister(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!registerBranch) return
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/pos/registers', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: registerBranch,
          name: form.get('name'),
          code: form.get('code'),
          is_active: true,
        }),
      })
      formElement.reset()
      setNotice('Register created for this branch.')
      await loadRegisters(registerBranch)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function toggleRegister(register: PosRegister) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/pos/registers/${register.id}`, {
        method: 'PUT',
        body: JSON.stringify({ is_active: !register.is_active }),
      })
      setNotice(register.is_active ? 'Register deactivated.' : 'Register activated.')
      await loadRegisters(registerBranch)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveSettings(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      setSettings(await apiRequest<PosSettingsRecord>('/pos/settings', {
        method: 'PUT',
        body: JSON.stringify({ variance_approval_threshold: Number(form.get('variance_approval_threshold')) }),
      }))
      setNotice('POS settings saved.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function managePin(staff: PosPinStaff, action: 'reset' | 'unlock') {
    if (action === 'reset' && !window.confirm(`Reset the POS PIN for ${staff.name}? They will need to set a new PIN before opening another shift.`)) return
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/pos/pins/${staff.id}/${action}`, { method: 'POST' })
      setNotice(action === 'reset' ? `POS PIN reset for ${staff.name}.` : `POS PIN unlocked for ${staff.name}.`)
      await loadPins()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const title = view === 'shifts' ? 'All shifts' : view === 'active' ? 'Active shifts' : view === 'staff-sales' ? 'Staff sales'
    : view === 'transactions' ? 'Sales transactions' : view === 'returns' ? 'Refunds & voids' : view === 'registers' ? 'Registers'
    : view === 'settings' ? 'POS settings' : 'PIN management'
  const description = view === 'shifts' ? 'Every shift opening and closing with floats, sales totals, and cash variances.'
    : view === 'active' ? 'Shifts currently open across your authorized branches, with live expected cash.'
    : view === 'staff-sales' ? 'Compare attributed sales by staff member with scoped branch, date, and item filters.'
    : view === 'transactions' ? 'Receipt-level POS transactions with cashier, shift, tender mix, and refund state.'
    : view === 'returns' ? 'Approved refunds and voids with the original receipt and approving staff member.'
    : view === 'registers' ? 'Named tills per branch. Only one open shift can use a register at a time.'
    : view === 'settings' ? 'Configure the cash-variance threshold that triggers manager PIN approval.'
    : 'POS PIN setup status, eligibility, lockouts, reset actions, and audit history. PIN values are never visible.'

  const filterFields = (modes: string[]) => (
    <form className="form-grid" onSubmit={(event) => { event.preventDefault(); void loadActiveView() }}>
      {modes.includes('dates') && <>
        <label className="field"><span>From date</span><input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} /></label>
        <label className="field"><span>To date</span><input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} /></label>
      </>}
      {modes.includes('branch') && <label className="field"><span>Branch</span><select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}><option value="">All authorized branches</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
      {modes.includes('staff') && <label className="field"><span>Staff member</span><select value={staffFilter} onChange={(event) => setStaffFilter(event.target.value)}><option value="">All staff</option>{staffOptions.map((staff) => <option key={staff.id} value={staff.id}>{staff.name}</option>)}</select></label>}
      {modes.includes('status') && <label className="field"><span>Status</span><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
        <option value="">All statuses</option>
        {modes.includes('shift-status') ? <><option value="open">Open</option><option value="closed">Closed</option></> : <><option value="completed">Completed</option><option value="refunded">Refunded</option><option value="voided">Voided</option></>}
      </select></label>}
      {modes.includes('shift') && <label className="field"><span>Shift ID</span><input inputMode="numeric" value={shiftFilter} onChange={(event) => setShiftFilter(event.target.value.replace(/[^0-9]/g, ''))} placeholder="Any shift" /></label>}
      {modes.includes('item') && <label className="field"><span>Item type</span><select value={itemType} onChange={(event) => setItemType(event.target.value)}><option value="">Products & services</option><option value="product">Products only</option><option value="service">Services only</option></select></label>}
      {modes.includes('payment') && <label className="field"><span>Payment method</span><select value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)}><option value="">All methods</option><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option></select></label>}
      {modes.includes('variance') && <label className="field field-inline"><input type="checkbox" checked={varianceOnly} onChange={(event) => setVarianceOnly(event.target.checked)} /><span>Only shifts with a cash variance</span></label>}
      <div className="pos-modal-actions"><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Loading…' : 'Apply filters'}</button></div>
    </form>
  )

  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">POS MANAGEMENT</span><h1>{title}</h1><p className="muted">{description}</p></div>
        <button className="button button-secondary" type="button" onClick={() => void loadActiveView()} disabled={busy}>↻ Refresh</button>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}

      {view === 'shifts' && (
        <section className="panel table-panel">
          {filterFields(['dates', 'branch', 'staff', 'status', 'shift-status', 'variance'])}
          {shifts?.data.length ? (
            <div className="table-scroll"><table><thead><tr><th>OPERATOR</th><th>BRANCH / REGISTER</th><th>OPENED</th><th>CLOSED</th><th>SALES</th><th>FLOAT</th><th>COUNTED</th><th>VARIANCE</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
              {shifts.data.map((row) => (
                <tr key={row.id}>
                  <td><strong>{row.operator_name}</strong></td>
                  <td>{row.branch_name}<small className="table-subtext">{row.register_name ?? row.terminal_name}</small></td>
                  <td>{formatDate(row.opened_at)}</td>
                  <td>{row.closed_at ? formatDate(row.closed_at) : '—'}</td>
                  <td>{money(row.sales_total ?? 0)}<small className="table-subtext">{row.sales_count ?? 0} sale(s)</small></td>
                  <td>{money(row.opening_float)}</td>
                  <td>{row.counted_cash === null ? '—' : money(row.counted_cash)}</td>
                  <td>{row.cash_variance === null ? '—' : <strong className={Number(row.cash_variance) === 0 ? 'pos-zero-variance' : 'pos-nonzero-variance'}>{money(row.cash_variance)}</strong>}</td>
                  <td><span className={`pos-status ${row.status}`}>{row.status}</span></td>
                  <td><button className="text-button" type="button" onClick={() => void openShiftDetail(row.id)}>Details</button></td>
                </tr>
              ))}
            </tbody></table></div>
          ) : <EmptyState title="No shifts found" message="Adjust the filters or wait for staff to open a shift." />}
          <Pagination page={shifts?.current_page ?? 1} totalPages={shifts?.last_page ?? 1} total={shifts?.total ?? 0} pageSize={shifts?.per_page ?? 20} onPage={(page) => void loadShifts(page)} label="All shifts pagination" />
        </section>
      )}

      {view === 'active' && (
        <section className="panel table-panel">
          {activeShifts.length ? (
            <div className="table-scroll"><table><thead><tr><th>OPERATOR</th><th>BRANCH / REGISTER</th><th>OPENED</th><th>SALES</th><th>CASH</th><th>M-PESA</th><th>CARD</th><th>EXPECTED CASH</th><th>ACTIONS</th></tr></thead><tbody>
              {activeShifts.map((row) => (
                <tr key={row.id}>
                  <td><strong>{row.operator_name}</strong></td>
                  <td>{row.branch_name}<small className="table-subtext">{row.register_name ?? row.terminal_name}</small></td>
                  <td>{formatDate(row.opened_at)}</td>
                  <td>{money(row.sales_total ?? 0)}<small className="table-subtext">{row.sales_count ?? 0} sale(s)</small></td>
                  <td>{money(row.totals?.cash_sales ?? 0)}</td>
                  <td>{money(row.totals?.mpesa_sales ?? 0)}</td>
                  <td>{money(row.totals?.card_sales ?? 0)}</td>
                  <td><strong>{money(row.totals?.expected_cash ?? 0)}</strong></td>
                  <td><button className="text-button" type="button" onClick={() => void openShiftDetail(row.id)}>Details</button></td>
                </tr>
              ))}
            </tbody></table></div>
          ) : <EmptyState title="No active shifts" message="Open shifts across your authorized branches will appear here." />}
        </section>
      )}

      {view === 'staff-sales' && (
        <>
          <section className="panel">{filterFields(['dates', 'branch', 'staff', 'shift', 'item', 'payment', 'status'])}</section>
          {report && (
            <>
              <div className="metrics-grid">
                <MetricCard label="Gross sales" value={Number(report.summary.gross_sales)} icon="¤" tone="sage" note={`${report.summary.transaction_count} transaction(s)`} />
                <MetricCard label="Net sales" value={Number(report.summary.net_sales)} icon="▣" tone="peach" note="Gross less refunds" />
                <MetricCard label="Refunds" value={Number(report.summary.refunds)} icon="↺" tone="lavender" note={`${report.summary.refund_count} refund(s)`} />
                <MetricCard label="Average basket" value={Number(report.summary.average_transaction_value)} icon="✳" tone="blue" note="Net sales / transactions" />
              </div>
              <section className="panel table-panel">
                <div className="panel-heading"><div><span className="eyebrow">ATTRIBUTION</span><h2>Sales by staff member</h2></div><span className="pos-item-count">Scoped to your branch authority</span></div>
                {report.by_staff.length ? (
                  <div className="table-scroll"><table><thead><tr><th>STAFF MEMBER</th><th>TRANSACTIONS</th><th>GROSS</th><th>DISCOUNTS</th><th>REFUNDS</th><th>NET</th></tr></thead><tbody>
                    {report.by_staff.map((row) => (
                      <tr key={row.staff_id}>
                        <td><strong>{row.staff_name}</strong></td>
                        <td>{row.transaction_count}</td>
                        <td>{money(row.gross_sales)}</td>
                        <td>{money(row.discounts)}</td>
                        <td>{money(row.refunds)}</td>
                        <td><strong>{money(row.net_sales)}</strong></td>
                      </tr>
                    ))}
                  </tbody></table></div>
                ) : <EmptyState title="No attributed sales" message="No completed transactions match these filters." />}
                <div className="table-scroll"><table><thead><tr><th>METHOD</th><th>TAKEN</th><th>REFUNDED</th></tr></thead><tbody>
                  <tr><td>Cash</td><td>{money(report.summary.payments.cash)}</td><td>{money(report.summary.refunds_by_method.cash)}</td></tr>
                  <tr><td>M-Pesa</td><td>{money(report.summary.payments.mpesa)}</td><td>{money(report.summary.refunds_by_method.mpesa)}</td></tr>
                  <tr><td>Card</td><td>{money(report.summary.payments.card)}</td><td>{money(report.summary.refunds_by_method.card)}</td></tr>
                  <tr><td><strong>Cash collected</strong></td><td colSpan={2}><strong>{money(report.summary.cash_collected)}</strong> · Outstanding {money(report.summary.outstanding)}</td></tr>
                </tbody></table></div>
              </section>
            </>
          )}
        </>
      )}

      {view === 'transactions' && (
        <section className="panel table-panel">
          {filterFields(['dates', 'branch', 'staff', 'shift', 'item', 'payment', 'status'])}
          {transactions?.data.length ? (
            <div className="table-scroll"><table><thead><tr><th>RECEIPT</th><th>SOLD</th><th>STAFF</th><th>BRANCH / REGISTER</th><th>PAYMENT</th><th>STATUS</th><th>TOTAL</th><th>REFUNDED</th><th>ACTIONS</th></tr></thead><tbody>
              {transactions.data.map((row) => (
                <tr key={row.id}>
                  <td><strong>{row.receipt_number}</strong><small className="table-subtext">{row.customer_name ?? 'Walk-in'}</small></td>
                  <td>{formatDate(row.sold_at)}</td>
                  <td>{row.cashier_name}</td>
                  <td>{row.branch_name}<small className="table-subtext">{row.register_name ?? `Shift ${row.shift_id}`}</small></td>
                  <td>{row.payment_methods.length ? row.payment_methods.join(', ') : '—'}</td>
                  <td><span className={`pos-status ${row.status}`}>{row.status}</span></td>
                  <td>{money(row.total_amount)}</td>
                  <td>{money(row.refunded_amount)}</td>
                  <td className="pos-sale-actions">
                    <button className="text-button" type="button" onClick={() => void apiDownload(`/documents/pos-sales/${row.id}/receipt`, `oshel-receipt-${row.receipt_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>Receipt PDF</button>
                    {row.shift_id ? <button className="text-button" type="button" onClick={() => void openShiftDetail(row.shift_id)}>Shift</button> : null}
                  </td>
                </tr>
              ))}
            </tbody></table></div>
          ) : <EmptyState title="No transactions" message="No POS transactions match these filters." />}
          <Pagination page={transactions?.current_page ?? 1} totalPages={transactions?.last_page ?? 1} total={transactions?.total ?? 0} pageSize={transactions?.per_page ?? 25} onPage={(page) => void loadTransactions(page)} label="Transactions pagination" />
        </section>
      )}

      {view === 'returns' && (
        <section className="panel table-panel">
          {filterFields(['dates', 'staff'])}
          {returns?.data.length ? (
            <div className="table-scroll"><table><thead><tr><th>REFUND</th><th>ORIGINAL RECEIPT</th><th>APPROVED BY</th><th>BRANCH</th><th>TENDER</th><th>AMOUNT</th><th>REASON</th><th>DATE</th></tr></thead><tbody>
              {returns.data.map((row) => (
                <tr key={row.id}>
                  <td><strong>{row.refund_number}</strong><small className="table-subtext">Sale {row.sale_status}</small></td>
                  <td>{row.receipt_number}</td>
                  <td>{row.approved_by_name}</td>
                  <td>{row.branch_name}</td>
                  <td>{row.tender_type}</td>
                  <td>{money(row.amount)}</td>
                  <td>{row.reason}</td>
                  <td>{formatDate(row.refunded_at)}</td>
                </tr>
              ))}
            </tbody></table></div>
          ) : <EmptyState title="No refunds or voids" message="Approved reversals within the selected period will appear here." />}
          <Pagination page={returns?.current_page ?? 1} totalPages={returns?.last_page ?? 1} total={returns?.total ?? 0} pageSize={returns?.per_page ?? 25} onPage={(page) => void loadReturns(page)} label="Returns pagination" />
        </section>
      )}

      {view === 'registers' && (
        <>
          <section className="panel">
            <div className="panel-heading"><div><span className="eyebrow">TILL SETUP</span><h2>Branch registers</h2></div>
              <label className="pos-select-label"><span>Branch</span><select value={registerBranch} onChange={(event) => setRegisterBranch(Number(event.target.value))}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
            </div>
            {registers.length ? (
              <div className="table-scroll"><table><thead><tr><th>NAME</th><th>CODE</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
                {registers.map((register) => (
                  <tr key={register.id}>
                    <td><strong>{register.name}</strong></td>
                    <td>{register.code}</td>
                    <td><span className={`status-pill ${register.is_active ? 'status-active' : 'status-inactive'}`}><i />{register.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td><button className="text-button" type="button" disabled={busy} onClick={() => void toggleRegister(register)}>{register.is_active ? 'Deactivate' : 'Activate'}</button></td>
                  </tr>
                ))}
              </tbody></table></div>
            ) : <EmptyState title="No registers at this branch" message="Add a named till so staff can open shifts against a specific register." />}
          </section>
          <section className="panel">
            <div className="panel-heading"><div><span className="eyebrow">NEW REGISTER</span><h2>Add a till</h2></div></div>
            <form className="form-grid" onSubmit={(event) => void createRegister(event)}>
              <label className="field"><span>Register name</span><input name="name" required maxLength={80} placeholder="Front counter" /></label>
              <label className="field"><span>Register code</span><input name="code" required maxLength={30} placeholder="FD-01" /></label>
              <div className="pos-modal-actions"><button className="button button-primary" type="submit" disabled={busy || !registerBranch}>Create register</button></div>
            </form>
          </section>
        </>
      )}

      {view === 'settings' && settings && (
        <section className="panel">
          <div className="panel-heading"><div><span className="eyebrow">CASH CONTROL</span><h2>Variance approval threshold</h2></div></div>
          <p className="muted">A closing cash variance above this amount requires an authorized manager PIN plus a written explanation. PIN lockout policy is currently {settings.pin_max_attempts} failed attempts for {settings.pin_lock_minutes} minutes.</p>
          <form className="form-grid" onSubmit={(event) => void saveSettings(event)}>
            <label className="field"><span>Threshold (KES)</span><input name="variance_approval_threshold" type="number" min="0" step="0.01" defaultValue={settings.variance_approval_threshold} required /></label>
            <div className="pos-modal-actions"><button className="button button-primary" type="submit" disabled={busy}>Save settings</button></div>
          </form>
        </section>
      )}

      {view === 'pins' && (
        <>
          <section className="panel table-panel">
            <div className="panel-heading"><div><span className="eyebrow">STAFF SECURITY</span><h2>POS PIN status</h2></div><span className="pos-item-count">PIN values and hashes are never displayed</span></div>
            {pins.length ? (
              <div className="table-scroll"><table><thead><tr><th>STAFF MEMBER</th><th>ROLES</th><th>SETUP</th><th>ELIGIBILITY</th><th>LOCK STATUS</th><th>LAST CHANGED</th><th>LAST VERIFIED</th><th>ACTIONS</th></tr></thead><tbody>
                {pins.map((staff) => (
                  <tr key={staff.id}>
                    <td><strong>{staff.name}</strong><small className="table-subtext">{staff.email ?? 'No email'}</small></td>
                    <td>{staff.roles.join(', ')}</td>
                    <td><span className={`status-pill ${staff.enrolled ? 'status-active' : 'status-inactive'}`}><i />{staff.enrolled ? 'Set' : 'Not set'}</span></td>
                    <td>{staff.pos_eligible ? 'POS eligible' : staff.is_active ? 'No POS access' : 'Suspended'}</td>
                    <td>{staff.locked ? `Locked until ${staff.locked_until ? formatDate(staff.locked_until) : 'later'}` : staff.failed_attempts > 0 ? `${staff.failed_attempts} failed attempt(s)` : 'Unlocked'}</td>
                    <td>{staff.last_changed_at ? formatDate(staff.last_changed_at) : '—'}</td>
                    <td>{staff.last_verified_at ? formatDate(staff.last_verified_at) : '—'}</td>
                    <td className="pos-sale-actions">
                      {staff.enrolled && <button className="text-button" type="button" disabled={busy} onClick={() => void managePin(staff, 'reset')}>Reset</button>}
                      {(staff.locked || staff.failed_attempts > 0) && <button className="text-button" type="button" disabled={busy} onClick={() => void managePin(staff, 'unlock')}>Unlock</button>}
                    </td>
                  </tr>
                ))}
              </tbody></table></div>
            ) : <EmptyState title="No staff PIN records" message="Eligible staff will appear here when their accounts are prepared for POS use." />}
          </section>
          <section className="panel table-panel">
            <div className="panel-heading"><div><span className="eyebrow">AUDIT TRAIL</span><h2>Recent PIN activity</h2></div><span className="pos-item-count">Latest 200 events · no PIN values recorded</span></div>
            {pinAudit.length ? (
              <div className="table-scroll"><table><thead><tr><th>ACTION</th><th>SUBJECT</th><th>ACTOR</th><th>IP</th><th>WHEN</th></tr></thead><tbody>
                {pinAudit.map((event) => (
                  <tr key={event.id}>
                    <td><strong>{formatAction(event.action)}</strong></td>
                    <td>{event.subject_id ?? '—'}</td>
                    <td>{event.user_id ?? '—'}</td>
                    <td>{event.ip_address ?? '—'}</td>
                    <td>{formatDate(event.occurred_at)}</td>
                  </tr>
                ))}
              </tbody></table></div>
            ) : <EmptyState title="No PIN activity yet" message="PIN setup, verification, reset, and unlock events will be listed here." />}
            <p className="muted">Staff set or change their own PIN from Point of sale, after re-entering their account password. Administrators can reset or unlock, but can never view an existing PIN.</p>
          </section>
        </>
      )}

      {selectedShift && (
        <div className="pos-modal-backdrop" role="presentation">
          <section className="pos-modal pos-shift-detail" role="dialog" aria-modal="true" aria-labelledby="pos-shift-detail-title">
            <div className="pos-modal-heading">
              <div><span className="eyebrow">SHIFT RECORD</span><h2 id="pos-shift-detail-title">{selectedShift.operator_name} · {selectedShift.register_name ?? selectedShift.terminal_name}</h2></div>
              <button className="button-icon-only" type="button" aria-label="Close shift detail" onClick={() => { setSelectedShift(null); setAdjustAction(null) }}>×</button>
            </div>
            <p className="muted">{selectedShift.branch_name} · {selectedShift.status} · opened {formatDate(selectedShift.opened_at)}{selectedShift.closed_at ? ` · closed ${formatDate(selectedShift.closed_at)}` : ''}</p>
            <div className="metrics-grid">
              <MetricCard label="Opening float" value={Number(selectedShift.opening_float)} icon="◈" tone="sage" note="Cash at start" />
              <MetricCard label="Sales" value={Number(selectedShift.totals.sales_total)} icon="¤" tone="peach" note={`${selectedShift.totals.sales_count} transaction(s)`} />
              <MetricCard label="Expected cash" value={Number(selectedShift.totals.expected_cash)} icon="▣" tone="blue" note="Calculated till position" />
              <MetricCard label="Variance" value={Number(selectedShift.cash_variance ?? 0)} icon="↺" tone="lavender" note={selectedShift.counted_cash === null ? 'Not yet counted' : `Counted ${money(selectedShift.counted_cash)}`} />
            </div>
            {selectedShift.sales.length > 0 && (
              <div className="table-scroll"><table><thead><tr><th>RECEIPT</th><th>STATUS</th><th>TOTAL</th><th>REFUNDED</th><th>SOLD</th></tr></thead><tbody>
                {selectedShift.sales.map((sale) => <tr key={sale.id}><td>{sale.receipt_number}</td><td>{sale.status}</td><td>{money(sale.total_amount)}</td><td>{money(sale.refunded_amount)}</td><td>{formatDate(sale.sold_at)}</td></tr>)}
              </tbody></table></div>
            )}
            {selectedShift.movements.length > 0 && (
              <div className="table-scroll"><table><thead><tr><th>MOVEMENT</th><th>AMOUNT</th><th>REASON</th><th>BY</th><th>WHEN</th></tr></thead><tbody>
                {selectedShift.movements.map((movement) => <tr key={movement.id}><td>{movement.movement_type}</td><td>{money(movement.amount)}</td><td>{movement.reason}</td><td>{movement.recorded_by_name}</td><td>{formatDate(movement.occurred_at)}</td></tr>)}
              </tbody></table></div>
            )}
            {selectedShift.variance_reason && <p className="muted">Variance explanation: {selectedShift.variance_reason}</p>}
            {selectedShift.adjustments.length > 0 && (
              <div className="table-scroll"><table><thead><tr><th>ACTION</th><th>REASON</th><th>BY</th><th>WHEN</th></tr></thead><tbody>
                {selectedShift.adjustments.map((adjustment) => <tr key={adjustment.id}><td>{adjustment.action}</td><td>{adjustment.reason}</td><td>{adjustment.adjusted_by_name}</td><td>{formatDate(adjustment.created_at)}</td></tr>)}
              </tbody></table></div>
            )}
            {selectedShift.status === 'closed' && user.permissions.includes('pos.shift.adjust') && (
              adjustAction ? (
                <form className="form-grid" onSubmit={(event) => void submitShiftAdjustment(event, adjustAction)}>
                  {adjustAction === 'adjust' && <label className="field"><span>Corrected counted cash (KES)</span><input name="counted_cash" type="number" min="0" step="0.01" defaultValue={selectedShift.counted_cash ?? ''} required /></label>}
                  {adjustAction === 'adjust' && <label className="field"><span>Variance explanation</span><input name="variance_reason" minLength={10} maxLength={1000} defaultValue={selectedShift.variance_reason ?? ''} /></label>}
                  <label className="field"><span>Audit reason (required)</span><textarea name="reason" minLength={10} maxLength={1000} rows={2} required /></label>
                  <div className="pos-modal-actions"><button className="button button-secondary" type="button" onClick={() => setAdjustAction(null)}>Cancel</button><button className="button button-primary" disabled={busy}>{adjustAction === 'adjust' ? 'Record adjustment' : 'Reopen shift'}</button></div>
                </form>
              ) : (
                <div className="pos-modal-actions">
                  <button className="button button-secondary" type="button" onClick={() => setAdjustAction('adjust')}>Adjust reconciliation</button>
                  <button className="button button-secondary" type="button" onClick={() => setAdjustAction('reopen')}>Reopen shift</button>
                </div>
              )
            )}
          </section>
        </div>
      )}
    </div>
  )
}

function InventoryWorkspacePage({
  data,
  user,
  section,
  onRefresh,
}: {
  data: InventoryOverview | null
  user: AuthUser
  section?: string
  onRefresh: () => void
}) {
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [imageProduct, setImageProduct] = useState<InventoryProduct | null>(null)
  const [editProduct, setEditProduct] = useState<InventoryProduct | null>(null)

  async function submit(
    event: FormEvent<HTMLFormElement>,
    endpoint: string,
    transform?: (form: FormData) => Record<string, unknown>,
  ) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const payload = transform ? transform(form) : Object.fromEntries(form.entries())
    try {
      await apiRequest(endpoint, { method: 'POST', body: JSON.stringify(payload) })
      setNotice('Inventory record saved.')
      formElement.reset()
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function postAction(endpoint: string, success: string) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(endpoint, { method: 'POST' })
      setNotice(success)
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function setProductOnlineVisibility(product: InventoryProduct) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/inventory/products/${product.id}/online-visibility`, {
        method: 'PATCH',
        body: JSON.stringify({ is_public: !product.is_public }),
      })
      setNotice(product.is_public ? 'Product removed from the online shop.' : 'Product published to the online shop.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  if (!data) return <LoadingState />
  const view = section || 'products'
  const stockValue = data.balances.reduce((total, balance) => total + Number(balance.valuation), 0)
  const canManage = user.permissions.includes('inventory.manage')
  const canAdjust = user.permissions.includes('inventory.adjust')
  const activeProducts = data.products.data.filter((product) => product.is_active)
  const productsPage = usePaginatedList(data.products.data)
  const balancesPage = usePaginatedList(data.balances)
  const batchesPage = usePaginatedList(data.batches)
  const movementsPage = usePaginatedList(data.movements)
  const reorderPage = usePaginatedList(data.reorder_alerts)
  const transfersPage = usePaginatedList(data.transfers)
  const stocktakesPage = usePaginatedList(data.stocktakes)

  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">STOCK CONTROL</span><h1>Inventory</h1><p className="muted">Products, branch stock, batches and immutable stock movement history.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>↻ Refresh</button>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      <div className="metrics-grid">
        {view === 'products' && <MetricCard label="Catalogue products" value={data.products.total} icon="▤" tone="sage" note="Active and archived" />}
        {(view === 'stock' || view === 'batches') && <MetricCard label="Stock value (KES)" value={Number(stockValue.toFixed(2))} icon="¤" tone="peach" note="Moving weighted average" />}
        {view === 'stock' && <MetricCard label="Reorder alerts" value={data.reorder_alerts.length} icon="!" tone="lavender" note="At or below reorder level" />}
        {view === 'transfers' && <MetricCard label="In transit (KES)" value={Number(Number(data.in_transit_valuation).toFixed(2))} icon="⇄" tone="blue" note={`${data.in_transit.length} transfer line(s)`} />}
        {view === 'movements' && <MetricCard label="Ledger entries" value={data.movements.length} icon="≡" tone="lavender" note="Recent posted movements" />}
      </div>
      {view === 'stock' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">REPLENISHMENT</span><h2>Reorder alerts</h2></div></div>
        {reorderPage.total ? <><div className="table-scroll"><table><thead><tr><th>PRODUCT</th><th>LOCATION</th><th>ON HAND</th><th>REORDER AT</th></tr></thead><tbody>
          {reorderPage.pageItems.map((alert, index) => <tr key={`${alert.sku}-${alert.location_name}-${index}`}><td><strong>{alert.product_name}</strong><small className="table-subtext">{alert.sku}</small></td><td>{alert.location_name}</td><td>{alert.quantity}</td><td>{alert.reorder_level}</td></tr>)}
        </tbody></table></div><Pagination page={reorderPage.currentPage} totalPages={reorderPage.totalPages} total={reorderPage.total} pageSize={reorderPage.pageSize} onPage={reorderPage.setCurrentPage} label="Reorder alerts pagination" /></> : <EmptyState title="Stock levels look healthy" message="Products at or below their reorder level will be listed here." />}
      </section>}
      {view === 'products' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">CATALOGUE</span><h2>Products</h2></div></div>
        {productsPage.total ? <><div className="table-scroll"><table><thead><tr><th>PRODUCT</th><th>IMAGES</th><th>CATEGORY</th><th>UNIT</th><th>SELLING PRICE</th><th>REORDER</th><th>TRACKING</th><th>ONLINE SHOP</th><th>ACTIONS</th></tr></thead><tbody>
          {productsPage.pageItems.map((product) => <tr key={product.id}><td><span className="catalogue-product-name">{product.primary_image_url && <img src={product.primary_image_url} alt="" loading="lazy" />}<span><strong>{product.name}</strong><small className="table-subtext">{product.sku}{product.is_active ? '' : ' · Archived'}</small></span></span></td><td>{canManage ? <button className="text-button" type="button" onClick={() => setImageProduct(product)}>Manage ({product.images?.length ?? 0})</button> : product.images?.length ?? 0}</td><td>{product.category_name ?? '—'}</td><td>{product.unit_of_measure}</td><td>{Number(product.selling_price).toFixed(2)}</td><td>{product.reorder_level}</td><td>{product.tracks_batches ? `Batch${product.tracks_expiry ? ' + expiry' : ''}` : 'Standard'}</td><td>{canManage && product.is_active ? <button className="text-button" type="button" disabled={busy} onClick={() => void setProductOnlineVisibility(product)}>{product.is_public ? 'Unpublish' : 'Publish'}</button> : product.is_public ? 'Published' : 'Not published'}</td><td>{canManage && <button className="text-button" type="button" onClick={() => setEditProduct(product)}>Edit</button>}</td></tr>)}
        </tbody></table></div><Pagination page={productsPage.currentPage} totalPages={productsPage.totalPages} total={productsPage.total} pageSize={productsPage.pageSize} onPage={productsPage.setCurrentPage} label="Products pagination" /></> : <EmptyState title="No products yet" message="Create the product catalogue before receiving or adjusting stock." />}
      </section>}
      {imageProduct && <CatalogueImageDialog
        title={`${imageProduct.name} images`}
        images={imageProduct.images ?? []}
        uploadEndpoint={`/inventory/products/${imageProduct.id}/images`}
        imageEndpoint={`/inventory/products/${imageProduct.id}/images`}
        onClose={() => setImageProduct(null)}
        onChanged={onRefresh}
      />}
      {editProduct && <ProductEditDialog
        product={editProduct}
        categories={data.categories}
        onClose={() => setEditProduct(null)}
        onSaved={() => { setEditProduct(null); onRefresh() }}
      />}
      {view === 'stock' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">BRANCH BALANCES</span><h2>Stock valuation</h2></div></div>
        {balancesPage.total ? <><div className="table-scroll"><table><thead><tr><th>PRODUCT</th><th>LOCATION</th><th>QUANTITY</th><th>AVG. UNIT COST</th><th>VALUE</th></tr></thead><tbody>
          {balancesPage.pageItems.map((balance) => <tr key={balance.id}><td><strong>{balance.product_name}</strong><small className="table-subtext">{balance.sku}</small></td><td>{balance.location_name}</td><td>{balance.quantity}</td><td>{Number(balance.average_unit_cost).toFixed(4)}</td><td>{Number(balance.valuation).toFixed(2)}</td></tr>)}
        </tbody></table></div><Pagination page={balancesPage.currentPage} totalPages={balancesPage.totalPages} total={balancesPage.total} pageSize={balancesPage.pageSize} onPage={balancesPage.setCurrentPage} label="Stock valuation pagination" /></> : <EmptyState title="No stock balances" message="Stock appears here when received or added through an approved adjustment." />}
      </section>}
      {view === 'batches' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">BATCH CONTROL</span><h2>Lots and expiry</h2></div></div>
        {batchesPage.total ? <><div className="table-scroll"><table><thead><tr><th>PRODUCT</th><th>LOT</th><th>LOCATION</th><th>ON HAND</th><th>EXPIRES</th></tr></thead><tbody>
          {batchesPage.pageItems.map((batch, index) => <tr key={`${batch.lot_code}-${batch.location_name}-${index}`}><td>{batch.product_name}</td><td>{batch.lot_code}</td><td>{batch.location_name}</td><td>{batch.quantity}</td><td>{batch.expires_on ?? '—'}</td></tr>)}
        </tbody></table></div><Pagination page={batchesPage.currentPage} totalPages={batchesPage.totalPages} total={batchesPage.total} pageSize={batchesPage.pageSize} onPage={batchesPage.setCurrentPage} label="Lots and expiry pagination" /></> : <EmptyState title="No active batches" message="Tracked lots and expiries appear when batch-controlled stock is received." />}
      </section>}
      {view === 'movements' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">STOCK LEDGER</span><h2>Recent movements</h2></div></div>
        {movementsPage.total ? <><div className="table-scroll"><table><thead><tr><th>TIME</th><th>PRODUCT</th><th>LOCATION</th><th>MOVEMENT</th><th>QUANTITY</th><th>VALUE</th></tr></thead><tbody>
          {movementsPage.pageItems.map((movement) => <tr key={movement.id}><td>{formatDate(movement.occurred_at)}</td><td>{movement.product_name}</td><td>{movement.location_name}</td><td>{movement.movement_type.replaceAll('_', ' ')}</td><td>{movement.quantity}</td><td>{movement.total_value}</td></tr>)}
        </tbody></table></div><Pagination page={movementsPage.currentPage} totalPages={movementsPage.totalPages} total={movementsPage.total} pageSize={movementsPage.pageSize} onPage={movementsPage.setCurrentPage} label="Movement ledger pagination" /></> : <EmptyState title="No stock movements" message="The ledger records receipts, transfers and reasoned adjustments." />}
      </section>}
      {view === 'transfers' && data.in_transit.length > 0 && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">BRANCH TRANSFERS</span><h2>In transit</h2></div></div>
        <div className="table-scroll"><table><thead><tr><th>REFERENCE</th><th>PRODUCT</th><th>FROM</th><th>QUANTITY</th><th>VALUE</th></tr></thead><tbody>
          {data.in_transit.map((line, index) => <tr key={`${line.reference}-${index}`}><td>{line.reference}</td><td>{line.product_name}</td><td>{line.from_location}</td><td>{line.quantity}</td><td>{(Number(line.quantity) * Number(line.unit_cost)).toFixed(2)}</td></tr>)}
        </tbody></table></div>
      </section>}
      {view === 'transfers' && canManage && <details className="panel clinical-template-admin" open>
        <summary>Create branch stock transfer</summary>
        <form className="form-grid" onSubmit={(event) => void submit(event, '/inventory/transfers', (form) => ({
          from_location_id: Number(form.get('from_location_id')),
          to_location_id: Number(form.get('to_location_id')),
          lines: [{
            product_id: Number(form.get('product_id')),
            batch_id: form.get('batch_id') ? Number(form.get('batch_id')) : null,
            quantity: Number(form.get('quantity')),
          }],
        }))}>
          <label>From location<select name="from_location_id" required>{data.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
          <label>To location<select name="to_location_id" required>{data.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
          <label>Product<select name="product_id" required>{activeProducts.map((product) => <option key={product.id} value={product.id}>{product.sku} — {product.name}</option>)}</select></label>
          <label>Lot/batch<select name="batch_id"><option value="">Untracked product</option>{data.batches.map((batch) => <option key={batch.batch_id} value={batch.batch_id}>{batch.product_name} — {batch.lot_code} ({batch.location_name})</option>)}</select></label>
          <label>Quantity<input name="quantity" type="number" min="0.001" step="0.001" required /></label>
          <button className="button button-primary" disabled={busy || data.locations.length < 2}>Create draft transfer</button>
        </form>
      </details>}
      {view === 'transfers' && transfersPage.total > 0 && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">TRANSFER TRACKING</span><h2>Branch transfers</h2></div></div>
        <div className="table-scroll"><table><thead><tr><th>REFERENCE</th><th>ROUTE / ITEMS</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {transfersPage.pageItems.map((transfer) => <tr key={transfer.id}><td>{transfer.reference}</td><td>{transfer.from_location} → {transfer.to_location}<small className="table-subtext">{transfer.lines.map((line) => `${line.product_name} × ${line.quantity}`).join(', ')}</small></td><td>{transfer.status}</td><td>
            {transfer.status === 'draft' && canAdjust && <button className="text-button" type="button" disabled={busy} onClick={() => void postAction(`/inventory/transfers/${transfer.id}/dispatch`, 'Transfer dispatched; stock is in transit.')}>Dispatch</button>}
            {transfer.status === 'dispatched' && canAdjust && <button className="text-button" type="button" disabled={busy} onClick={() => void postAction(`/inventory/transfers/${transfer.id}/receive`, 'Transfer received; destination stock updated.')}>Receive</button>}
          </td></tr>)}
        </tbody></table></div>
        <Pagination page={transfersPage.currentPage} totalPages={transfersPage.totalPages} total={transfersPage.total} pageSize={transfersPage.pageSize} onPage={transfersPage.setCurrentPage} label="Branch transfers pagination" />
      </section>}
      {view === 'stocktakes' && canManage && <details className="panel clinical-template-admin" open>
        <summary>Start a stocktake</summary>
        <form className="form-grid" onSubmit={(event) => void submit(event, '/inventory/stocktakes', (form) => ({
          location_id: Number(form.get('location_id')),
          lines: [{
            product_id: Number(form.get('product_id')),
            batch_id: form.get('batch_id') ? Number(form.get('batch_id')) : null,
            counted_quantity: Number(form.get('counted_quantity')),
          }],
        }))}>
          <label>Location<select name="location_id" required>{data.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
          <label>Product<select name="product_id" required>{activeProducts.map((product) => <option key={product.id} value={product.id}>{product.sku} — {product.name}</option>)}</select></label>
          <label>Batch (required for tracked products)<select name="batch_id"><option value="">Non-batch stock</option>{data.batches.map((batch) => <option key={batch.batch_id} value={batch.batch_id}>{batch.product_name} — {batch.lot_code}</option>)}</select></label>
          <label>Counted quantity<input name="counted_quantity" type="number" min="0" step="0.001" required /></label>
          <button className="button button-primary" disabled={busy || data.locations.length === 0}>Save count</button>
        </form>
      </details>}
      {view === 'stocktakes' && stocktakesPage.total > 0 && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">COUNT APPROVAL</span><h2>Stocktakes</h2></div></div>
        <div className="table-scroll"><table><thead><tr><th>REFERENCE</th><th>LOCATION</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {stocktakesPage.pageItems.map((stocktake) => <tr key={stocktake.id}><td>{stocktake.reference}</td><td>{stocktake.location_name}</td><td>{stocktake.status}</td><td>{stocktake.status === 'counted' && canAdjust && stocktake.created_by !== user.id && <button className="text-button" type="button" disabled={busy} onClick={() => void postAction(`/inventory/stocktakes/${stocktake.id}/post`, 'Stocktake adjustments posted by an independent reviewer.')}>Post count</button>}</td></tr>)}
        </tbody></table></div>
        <Pagination page={stocktakesPage.currentPage} totalPages={stocktakesPage.totalPages} total={stocktakesPage.total} pageSize={stocktakesPage.pageSize} onPage={stocktakesPage.setCurrentPage} label="Stocktakes pagination" />
      </section>}
      {view === 'setup' && canManage && <div className="panel clinical-template-admin">
        <div className="form-grid">
          <form className="form-stack" onSubmit={(event) => void submit(event, '/inventory/categories')}>
            <h3>Create category</h3><label>Category name<input name="name" required maxLength={120} /></label>
            <label>Description<input name="description" maxLength={500} /></label><button className="button button-secondary" disabled={busy}>Save category</button>
          </form>
          <form className="form-stack" onSubmit={(event) => void submit(event, '/inventory/suppliers')}>
            <h3>Create supplier</h3><label>Supplier name<input name="name" required maxLength={160} /></label>
            <label>Email<input name="email" type="email" /></label><label>Phone<input name="phone" /></label>
            <label>Currency<input name="currency_code" defaultValue="KES" required maxLength={3} /></label>
            <button className="button button-secondary" disabled={busy}>Save supplier</button>
          </form>
          <form className="form-stack" onSubmit={(event) => void submit(event, '/inventory/products', (form) => ({
            ...Object.fromEntries(form.entries()),
            category_id: form.get('category_id') || null,
            selling_price: Number(form.get('selling_price')),
            reorder_level: Number(form.get('reorder_level')),
            tracks_batches: form.get('tracks_batches') === 'on',
            tracks_expiry: form.get('tracks_expiry') === 'on',
            is_public: form.get('is_public') === 'on',
          }))}>
            <h3>Create product</h3><label>SKU<input name="sku" required maxLength={80} /></label>
            <label>Product name<input name="name" required maxLength={180} /></label>
            <label>Category<select name="category_id"><option value="">Uncategorised</option>{data.categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
            <label>Unit of measure<input name="unit_of_measure" required defaultValue="each" /></label>
            <label>Selling price<input name="selling_price" type="number" min="0" step="0.01" required /></label>
            <label>Reorder level<input name="reorder_level" type="number" min="0" step="0.001" required /></label>
            <label className="checkbox-label"><input name="tracks_batches" type="checkbox" /> Track lots/batches</label>
            <label className="checkbox-label"><input name="tracks_expiry" type="checkbox" /> Track expiry dates</label>
            <label className="checkbox-label"><input name="is_public" type="checkbox" /> Publish in online shop</label>
            <button className="button button-secondary" disabled={busy}>Save product</button>
          </form>
          <form className="form-stack" onSubmit={(event) => void submit(event, '/inventory/locations', (form) => ({
            ...Object.fromEntries(form.entries()),
            branch_id: Number(form.get('branch_id')),
          }))}>
            <h3>Create stock location</h3>            <label>Branch<select name="branch_id" required>{data.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
            <label>Location name<input name="name" required /></label><label>Code<input name="code" required /></label>
            <button className="button button-secondary" disabled={busy}>Save location</button>
          </form>
        </div>
      </div>}
      {view === 'stock' && canAdjust && <details className="panel clinical-template-admin" open>
        <summary>Record stock adjustment</summary>
        <form className="form-grid" onSubmit={(event) => void submit(event, '/inventory/adjustments', (form) => ({
          ...Object.fromEntries(form.entries()),
          location_id: Number(form.get('location_id')),
          product_id: Number(form.get('product_id')),
          quantity_delta: Number(form.get('quantity_delta')),
          unit_cost: form.get('unit_cost') ? Number(form.get('unit_cost')) : null,
        }))}>
          <label>Location<select name="location_id" required>{data.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
          <label>Product<select name="product_id" required>{activeProducts.map((product) => <option key={product.id} value={product.id}>{product.sku} — {product.name}</option>)}</select></label>
          <label>Quantity change<input name="quantity_delta" type="number" step="0.001" required /></label>
          <label>Unit cost (required for additions)<input name="unit_cost" type="number" min="0" step="0.0001" /></label>
          <label>Lot code<input name="lot_code" maxLength={100} /></label>
          <label>Expiry date<input name="expires_on" type="date" /></label>
          <label>Reason<input name="reason" required maxLength={1000} /></label>
          <button className="button button-primary" disabled={busy}>Post adjustment</button>
        </form>
      </details>}
      <p className="muted">Valuation method: {data.costing_method.replaceAll('_', ' ')}. Posted stock movements are retained in the ledger; corrections use compensating movements.</p>
    </div>
  )
}

function ProductEditDialog({
  product,
  categories,
  onClose,
  onSaved,
}: {
  product: InventoryProduct
  categories: { id: number; name: string }[]
  onClose: () => void
  onSaved: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest(`/inventory/products/${product.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          sku: String(form.get('sku')),
          barcode: String(form.get('barcode') ?? '') || null,
          name: String(form.get('name')),
          category_id: form.get('category_id') ? Number(form.get('category_id')) : null,
          brand: String(form.get('brand') ?? '') || null,
          description: String(form.get('description') ?? '') || null,
          unit_of_measure: String(form.get('unit_of_measure')),
          tax_category: String(form.get('tax_category') ?? '') || null,
          selling_price: Number(form.get('selling_price')),
          reorder_level: Number(form.get('reorder_level')),
          tracks_batches: form.get('tracks_batches') === 'on',
          tracks_expiry: form.get('tracks_expiry') === 'on',
          is_public: form.get('is_public') === 'on',
          is_active: form.get('is_active') === 'on',
        }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="product-edit-title">
        <div className="dialog-heading"><div><span className="eyebrow">INVENTORY CATALOGUE</span><h2 id="product-edit-title">Edit product</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="customer-form form-stack" onSubmit={(event) => void submit(event)}>
          <section className="form-section">
            <div className="form-section-heading"><strong>Product identity</strong><small>How this item is named and identified across the catalogue.</small></div>
            <div className="form-section-grid"><label>SKU<input name="sku" required maxLength={80} defaultValue={product.sku} /></label><label>Barcode<input name="barcode" maxLength={100} defaultValue={product.barcode ?? ''} /></label></div>
            <label>Product name<input name="name" required maxLength={180} defaultValue={product.name} /></label>
            <div className="form-section-grid"><label>Category<select name="category_id" defaultValue={product.category_id ?? ''}><option value="">Uncategorised</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label><label>Brand<input name="brand" maxLength={120} defaultValue={product.brand ?? ''} /></label></div>
            <label>Description<textarea name="description" rows={3} maxLength={5000} defaultValue={product.description ?? ''} /></label>
          </section>
          <section className="form-section">
            <div className="form-section-heading"><strong>Pricing &amp; stock control</strong><small>Set the selling price, tax treatment and reorder threshold.</small></div>
            <div className="form-section-grid"><label>Unit of measure<input name="unit_of_measure" required defaultValue={product.unit_of_measure} /></label><label>Tax category<input name="tax_category" maxLength={80} defaultValue={product.tax_category ?? ''} /></label></div>
            <div className="form-section-grid"><label>Selling price<input name="selling_price" type="number" min={0} step="0.01" required defaultValue={Number(product.selling_price)} /></label><label>Reorder level<input name="reorder_level" type="number" min={0} step="0.001" required defaultValue={Number(product.reorder_level)} /></label></div>
          </section>
          <section className="form-section">
            <div className="form-section-heading"><strong>Availability</strong><small>Choose how the product is tracked and where it is shown.</small></div>
            <label className="customer-check"><input name="tracks_batches" type="checkbox" defaultChecked={product.tracks_batches} /> Track lots/batches</label>
            <label className="customer-check"><input name="tracks_expiry" type="checkbox" defaultChecked={product.tracks_expiry} /> Track expiry dates</label>
            <label className="customer-check"><input name="is_public" type="checkbox" defaultChecked={product.is_public} /> Publish in online shop</label>
            <label className="customer-check"><input name="is_active" type="checkbox" defaultChecked={product.is_active} /> Active in the product catalogue</label>
          </section>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save product'}</button></div>
        </form>
      </section>
    </div>
  )
}

function ProcurementWorkspacePage({
  data,
  user,
  branches,
  section,
  onRefresh,
}: {
  data: { procurement: ProcurementOverview; inventory: InventoryOverview | null } | null
  user: AuthUser
  branches: Branch[]
  section?: string
  onRefresh: () => void
}) {
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  if (!data) return <LoadingState />
  const inventory = data.inventory
  const procurement = data.procurement
  const canManage = user.permissions.includes('procurement.manage')
  const canApprove = user.permissions.includes('procurement.approve')
  const canReceive = user.permissions.includes('procurement.receive')
  const canInvoice = user.permissions.includes('procurement.invoice.manage')
  const view = section || 'requisitions'
  const requisitionsPage = usePaginatedList(procurement.requisitions)
  const ordersPage = usePaginatedList(procurement.purchase_orders)
  const invoicesPage = usePaginatedList(procurement.supplier_invoices)

  async function send(endpoint: string, method: string, payload: Record<string, unknown>, success: string) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(endpoint, { method, body: JSON.stringify(payload) })
      setNotice(success)
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">SUPPLIER WORKFLOW</span><h1>Procurement</h1><p className="muted">Requisition, independent approval, purchase order, receipt and invoice matching.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>↻ Refresh</button>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      {view === 'requisitions' && canManage && inventory && <details className="panel clinical-template-admin" open={procurement.requisitions.length === 0}>
        <summary>Create purchase requisition</summary>
        <form className="form-grid" onSubmit={(event) => {
          event.preventDefault()
          const form = new FormData(event.currentTarget)
          void send('/procurement/requisitions', 'POST', {
            branch_id: Number(form.get('branch_id')),
            supplier_id: form.get('supplier_id') ? Number(form.get('supplier_id')) : null,
            notes: form.get('notes'),
            lines: [{ product_id: Number(form.get('product_id')), quantity: Number(form.get('quantity')) }],
          }, 'Requisition submitted for independent review.')
          event.currentTarget.reset()
        }}>
          <label>Branch<select name="branch_id" required>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label>Supplier<select name="supplier_id"><option value="">Choose later</option>{inventory.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></label>
          <label>Product<select name="product_id" required>{inventory.products.data.map((product) => <option key={product.id} value={product.id}>{product.sku} — {product.name}</option>)}</select></label>
          <label>Quantity<input name="quantity" type="number" min="0.001" step="0.001" required /></label>
          <label>Notes<input name="notes" maxLength={3000} /></label>
          <button className="button button-primary" disabled={busy}>Submit requisition</button>
        </form>
      </details>}
      {view === 'requisitions' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">REQUESTS</span><h2>Purchase requisitions</h2></div></div>
        {requisitionsPage.total ? <><div className="table-scroll"><table><thead><tr><th>REFERENCE</th><th>ITEMS</th><th>SUPPLIER</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {requisitionsPage.pageItems.map((req) => <tr key={req.id}><td>{req.reference}</td><td>{req.lines.map((line) => `${line.product_name} × ${line.quantity}`).join(', ')}</td><td>{req.supplier_name ?? 'To be selected'}</td><td><StatusPill active={req.status === 'approved' || req.status === 'ordered'} /><small className="table-subtext">{req.status}</small></td><td>
            {canApprove && req.status === 'pending' && req.requested_by !== user.id && <><button className="text-button" type="button" disabled={busy} onClick={() => void send(`/procurement/requisitions/${req.id}/decision`, 'PATCH', { status: 'approved' }, 'Requisition approved.')}>Approve</button>{' · '}<button className="text-button" type="button" disabled={busy} onClick={() => void send(`/procurement/requisitions/${req.id}/decision`, 'PATCH', { status: 'rejected' }, 'Requisition rejected.')}>Reject</button></>}
            {canManage && inventory && req.status === 'approved' && <details><summary>Create PO</summary><form className="form-stack" onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              const supplierId = Number(form.get('supplier_id'))
              const lines = req.lines.map((line) => ({ product_id: line.product_id, unit_cost: Number(form.get(`cost_${line.product_id}`)) }))
              void send('/procurement/purchase-orders', 'POST', {
                requisition_id: req.id,
                supplier_id: supplierId,
                currency_code: String(form.get('currency_code')),
                lines,
              }, 'Draft purchase order created; it must be approved by another user.')
            }}>
              <label>Supplier<select name="supplier_id" defaultValue={String(req.supplier_id ?? '')} required>{inventory.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></label>
              <label>Currency<input name="currency_code" defaultValue="KES" maxLength={3} required /></label>
              {req.lines.map((line) => <label key={line.product_id}>{line.product_name} unit cost<input name={`cost_${line.product_id}`} type="number" min="0" step="0.0001" required /></label>)}
              <button className="button button-secondary" disabled={busy}>Create draft PO</button>
            </form></details>}
          </td></tr>)}
        </tbody></table></div><Pagination page={requisitionsPage.currentPage} totalPages={requisitionsPage.totalPages} total={requisitionsPage.total} pageSize={requisitionsPage.pageSize} onPage={requisitionsPage.setCurrentPage} label="Requisitions pagination" /></> : <EmptyState title="No requisitions" message="Inventory teams can submit a request for independent approval." />}
      </section>}
      {view === 'orders' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">SUPPLIER ORDERS</span><h2>Purchase orders</h2></div></div>
        {ordersPage.total ? <><div className="table-scroll"><table><thead><tr><th>REFERENCE</th><th>SUPPLIER / ITEMS</th><th>STATUS</th><th>WORKFLOW</th></tr></thead><tbody>
          {ordersPage.pageItems.map((order) => <tr key={order.id}><td>{order.reference}<small className="table-subtext">{order.currency_code}</small></td><td>{order.supplier_name}<small className="table-subtext">{order.lines.map((line) => `${line.product_name} ${line.quantity_received}/${line.quantity_ordered}`).join(', ')}</small></td><td>{order.status}</td><td>
            {canApprove && order.status === 'draft' && order.created_by !== user.id && <button className="text-button" type="button" disabled={busy} onClick={() => void send(`/procurement/purchase-orders/${order.id}/approve`, 'POST', {}, 'Purchase order approved.')}>Approve PO</button>}
            {canReceive && inventory && ['approved', 'partially_received'].includes(order.status) && order.lines.map((line) => Number(line.quantity_ordered) > Number(line.quantity_received) && <details key={line.id}><summary>Receive {line.product_name}</summary><form className="form-stack" onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              const lineData: Record<string, unknown> = {
                purchase_order_line_id: line.id,
                quantity: Number(form.get('quantity')),
              }
              if (line.tracks_batches) lineData.lot_code = form.get('lot_code')
              if (line.tracks_expiry) lineData.expires_on = form.get('expires_on')
              void send(`/procurement/purchase-orders/${order.id}/receive`, 'POST', {
                location_id: Number(form.get('location_id')),
                lines: [lineData],
              }, 'Goods receipt posted to stock.')
            }}>
              <label>Receiving location<select name="location_id" required>{inventory.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
              <label>Quantity<input name="quantity" type="number" min="0.001" max={Number(line.quantity_ordered) - Number(line.quantity_received)} step="0.001" defaultValue={Number(line.quantity_ordered) - Number(line.quantity_received)} required /></label>
              {line.tracks_batches && <label>Lot code<input name="lot_code" required maxLength={100} /></label>}
              {line.tracks_expiry && <label>Expiry<input name="expires_on" type="date" required /></label>}
              <button className="button button-secondary" disabled={busy}>Post GRN</button>
            </form></details>)}
            {canInvoice && order.grns.map((grn) => <form key={grn.id} className="form-stack" onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              void send(`/procurement/purchase-orders/${order.id}/supplier-invoices`, 'POST', {
                grn_id: grn.id,
                invoice_number: form.get('invoice_number'),
                total_amount: Number(form.get('total_amount')),
                invoice_date: form.get('invoice_date'),
                due_date: form.get('due_date') || null,
              }, 'Supplier invoice matched and recorded for accounts payable.')
            }}>
              <strong>Invoice for {grn.reference}</strong>
              <label>Invoice number<input name="invoice_number" required maxLength={100} /></label>
              <label>Matched total<input name="total_amount" type="number" step="0.01" defaultValue={grn.total_amount.toFixed(2)} required /></label>
              <label>Invoice date<input name="invoice_date" type="date" required /></label>
              <label>Due date<input name="due_date" type="date" /></label>
              <button className="button button-secondary" disabled={busy}>Record invoice</button>
            </form>)}
          </td></tr>)}
        </tbody></table></div><Pagination page={ordersPage.currentPage} totalPages={ordersPage.totalPages} total={ordersPage.total} pageSize={ordersPage.pageSize} onPage={ordersPage.setCurrentPage} label="Purchase orders pagination" /></> : <EmptyState title="No purchase orders" message="Approved requisitions can be converted into draft orders." />}
      </section>}
      {view === 'invoices' && canInvoice && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">ACCOUNTS PAYABLE</span><h2>Supplier invoices</h2></div></div>
        {invoicesPage.total ? <><div className="table-scroll"><table><thead><tr><th>INVOICE</th><th>SUPPLIER</th><th>AMOUNT</th><th>DUE</th><th>STATUS</th></tr></thead><tbody>
          {invoicesPage.pageItems.map((invoice) => <tr key={invoice.id}><td>{invoice.invoice_number}</td><td>{invoice.supplier_name}</td><td>{Number(invoice.total_amount).toFixed(2)}</td><td>{invoice.due_date ?? '—'}</td><td>Recorded</td></tr>)}
        </tbody></table></div><Pagination page={invoicesPage.currentPage} totalPages={invoicesPage.totalPages} total={invoicesPage.total} pageSize={invoicesPage.pageSize} onPage={invoicesPage.setCurrentPage} label="Supplier invoices pagination" /></> : <EmptyState title="No supplier invoices" message="Invoices matched to goods receipts will appear here for accounts payable." />}
      </section>}
    </div>
  )
}

function HrWorkspacePage({
  data,
  user,
  branches,
  section,
  onRefresh,
}: {
  data: {
    employees: Paginated<EmployeeRecord>
    shifts: Paginated<Record<string, unknown>>
    attendance: Paginated<Record<string, unknown>>
    leaveRequests: Paginated<Record<string, unknown>>
  } | null
  user: AuthUser
  branches: Branch[]
  section?: string
  onRefresh: () => void
}) {
  const [teamUsers, setTeamUsers] = useState<ApiUser[]>([])
  const [availableBranches, setAvailableBranches] = useState<Branch[]>([])
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const canManage = user.permissions.includes('hr.manage')
  const canCreateEmployee = canManage && user.permissions.includes('users.view')
  const canAttendance = user.permissions.includes('attendance.manage')
  const canLeave = user.permissions.includes('leave.manage')

  useEffect(() => {
    if (!canCreateEmployee) return
    let active = true
    apiRequest<Paginated<ApiUser>>('/users').then((result) => {
      if (active) setTeamUsers(result.data)
    }).catch((requestError: unknown) => {
      if (active) setNotice(getErrorMessage(requestError))
    })
    return () => { active = false }
  }, [canCreateEmployee])

  useEffect(() => {
    let active = true
    apiRequest<Branch[]>('/branches').then((result) => {
      if (active) setAvailableBranches(result)
    }).catch((requestError: unknown) => {
      if (active) setNotice(getErrorMessage(requestError))
    })
    return () => { active = false }
  }, [])

  const branchChoices = branches.length ? branches : availableBranches

  async function submitRecord(
    event: FormEvent<HTMLFormElement>,
    endpoint: string,
    method = 'POST',
  ) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const payload: Record<string, FormDataEntryValue | Record<string, string>> = Object.fromEntries(form.entries())
    if (endpoint === '/hr/employees') {
      const contractType = String(form.get('contract[type]') ?? '')
      const contractStart = String(form.get('contract[starts_on]') ?? '')
      delete payload['contract[type]']
      delete payload['contract[starts_on]']
      if (contractStart) {
        payload.contract = {
          type: contractType,
          starts_on: contractStart,
          ends_on: String(form.get('contract[ends_on]') ?? '') || '',
          terms: String(form.get('contract[terms]') ?? ''),
        }
      } else {
        delete payload.contract
      }
    }
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(endpoint, { method, body: JSON.stringify(payload) })
      setNotice('HR record saved.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function clockIn(employeeId: number) {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/hr/attendance/clock-in', {
        method: 'POST',
        body: JSON.stringify({ employee_id: employeeId }),
      })
      setNotice('Attendance clock-in recorded.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function reviewLeave(id: number, status: 'approved' | 'rejected') {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(`/hr/leave-requests/${id}/decision`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      })
      setNotice(`Leave request ${status}.`)
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const employees = data?.employees.data ?? []
  const requests = data?.leaveRequests.data ?? []
  const view = section || 'employees'
  const employeesPage = usePaginatedList(employees)
  const shiftsPage = usePaginatedList(data?.shifts.data ?? [])
  const attendancePage = usePaginatedList(data?.attendance.data ?? [])
  const leavePage = usePaginatedList(requests)

  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">TEAM OPERATIONS</span><h1>People & HR</h1><p className="muted">Employee records, contracts, shifts, attendance and leave.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>↻ <span className="hide-small">Refresh</span></button>
      </div>
      {notice && <div className="notice" role="status">{notice}</div>}
      <div className="clinical-summary">
        <div><strong>{data?.employees.total ?? 0}</strong><span>Team members</span></div>
        <div><strong>{data?.shifts.total ?? 0}</strong><span>Scheduled shifts</span></div>
        <div><strong>{data?.attendance.total ?? 0}</strong><span>Attendance records</span></div>
        <div><strong>{requests.filter((request) => request.status === 'pending').length}</strong><span>Pending leave</span></div>
      </div>
      {view === 'employees' && canCreateEmployee && <details className="panel clinical-template-admin" open>
        <summary>Create an employee profile</summary>
        <form className="form-grid" onSubmit={(event) => void submitRecord(event, '/hr/employees')}>
          <label className="field">Existing team account<select name="user_id" required defaultValue=""><option value="" disabled>Select account</option>{teamUsers.map((member) => <option value={member.id} key={member.id}>{member.name} · {member.email}</option>)}</select></label>
          <label className="field">Branch<select name="branch_id" required defaultValue={String(user.branch_id ?? branchChoices[0]?.id ?? '')}>{branchChoices.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label className="field">Employee number<input name="employee_number" required maxLength={40} /></label>
          <label className="field">Job title<input name="job_title" required maxLength={120} /></label>
          <label className="field">Employment type<select name="employment_type"><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="contractor">Contractor</option><option value="temporary">Temporary</option></select></label>
          <label className="field">Hire date<input name="hired_on" type="date" required /></label>
          <label className="field">Contract type<select name="contract[type]"><option value="permanent">Permanent</option><option value="fixed_term">Fixed term</option><option value="probation">Probation</option><option value="service">Service</option></select></label>
          <label className="field">Contract starts<input name="contract[starts_on]" type="date" /></label>
          <button className="button button-primary" disabled={busy}>Save employee profile</button>
        </form>
      </details>}
      {view === 'shifts' && canManage && <details className="panel clinical-template-admin" open>
        <summary>Schedule a shift</summary>
        <form className="form-grid" onSubmit={(event) => void submitRecord(event, '/hr/shifts')}>
          <label className="field">Employee<select name="employee_id" required defaultValue=""><option value="" disabled>Select employee</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name} · {employee.employee_number}</option>)}</select></label>
          <label className="field">Branch<select name="branch_id" required defaultValue={String(user.branch_id ?? branchChoices[0]?.id ?? '')}>{branchChoices.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label className="field">Date<input name="shift_date" type="date" required /></label>
          <label className="field">Starts<input name="starts_at" type="time" required /></label>
          <label className="field">Ends<input name="ends_at" type="time" required /></label>
          <button className="button button-primary" disabled={busy}>Save shift</button>
        </form>
      </details>}
      {view === 'leave' && canLeave && <details className="panel clinical-template-admin" open>
        <summary>Record a leave request</summary>
        <form className="form-grid" onSubmit={(event) => void submitRecord(event, '/hr/leave-requests')}>
          <label className="field">Employee<select name="employee_id" required defaultValue=""><option value="" disabled>Select employee</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select></label>
          <label className="field">Leave type<select name="leave_type"><option value="annual">Annual</option><option value="sick">Sick</option><option value="parental">Parental</option><option value="unpaid">Unpaid</option><option value="other">Other</option></select></label>
          <label className="field">From<input name="starts_on" type="date" required /></label>
          <label className="field">To<input name="ends_on" type="date" required /></label>
          <label className="field field-wide">Reason<textarea name="reason" required rows={2} /></label>
          <button className="button button-primary" disabled={busy}>Submit leave request</button>
        </form>
      </details>}
      {view === 'employees' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">EMPLOYEE DIRECTORY</span><h2>Team members</h2></div></div>
        {employeesPage.total ? <><div className="table-scroll"><table><thead><tr><th>EMPLOYEE</th><th>ROLE</th><th>NUMBER</th><th>BRANCH</th><th>STATUS</th><th>ATTENDANCE</th></tr></thead><tbody>
          {employeesPage.pageItems.map((employee) => <tr key={employee.id}><td><strong>{employee.name}</strong><small className="table-subtext">{employee.email}</small></td><td>{employee.job_title}</td><td>{employee.employee_number}</td><td>{branches.find((branch) => branch.id === employee.branch_id)?.name ?? 'Assigned branch'}</td><td><StatusPill active={employee.status === 'active'} /></td><td>{canAttendance && <button className="text-button" type="button" disabled={busy} onClick={() => void clockIn(employee.id)}>Clock in</button>}</td></tr>)}
        </tbody></table></div><Pagination page={employeesPage.currentPage} totalPages={employeesPage.totalPages} total={employeesPage.total} pageSize={employeesPage.pageSize} onPage={employeesPage.setCurrentPage} label="Employees pagination" /></> : <EmptyState title="No employee profiles" message="Create employee profiles from existing team accounts." />}
      </section>}
      {view === 'shifts' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">SHIFT ROSTER</span><h2>Scheduled shifts</h2></div></div>
        {shiftsPage.total > 0 ? <><div className="table-scroll"><table><thead><tr><th>EMPLOYEE</th><th>DATE</th><th>HOURS</th><th>BRANCH</th><th>STATUS</th></tr></thead><tbody>
          {shiftsPage.pageItems.map((shift) => <tr key={String(shift.id)}><td>{String(shift.employee_name ?? '')}</td><td>{String(shift.shift_date ?? '')}</td><td>{String(shift.starts_at ?? '')}–{String(shift.ends_at ?? '')}</td><td>{branches.find((branch) => branch.id === Number(shift.branch_id))?.name ?? 'Assigned branch'}</td><td>{String(shift.status ?? '')}</td></tr>)}
        </tbody></table></div><Pagination page={shiftsPage.currentPage} totalPages={shiftsPage.totalPages} total={shiftsPage.total} pageSize={shiftsPage.pageSize} onPage={shiftsPage.setCurrentPage} label="Shifts pagination" /></> : <EmptyState title="No shifts scheduled" message="Create shifts for your team from the action above." />}
      </section>}
      {view === 'attendance' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">ATTENDANCE</span><h2>Attendance register</h2></div></div>
        {attendancePage.total > 0 ? <><div className="table-scroll"><table><thead><tr><th>EMPLOYEE</th><th>DATE</th><th>IN</th><th>OUT</th><th>STATUS</th><th /></tr></thead><tbody>
          {attendancePage.pageItems.map((record) => <tr key={String(record.id)}><td>{String(record.employee_name ?? '')}</td><td>{String(record.work_date ?? '')}</td><td>{record.clocked_in_at ? formatDate(String(record.clocked_in_at)) : '—'}</td><td>{record.clocked_out_at ? formatDate(String(record.clocked_out_at)) : '—'}</td><td>{String(record.status ?? '')}</td><td>{canAttendance && Boolean(record.clocked_in_at) && !record.clocked_out_at && <button className="text-button" type="button" disabled={busy} onClick={() => {
            void apiRequest(`/hr/attendance/${record.id}/clock-out`, { method: 'POST' })
              .then(() => { setNotice('Attendance clock-out recorded.'); onRefresh() })
              .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
          }}>Clock out</button>}</td></tr>)}
        </tbody></table></div><Pagination page={attendancePage.currentPage} totalPages={attendancePage.totalPages} total={attendancePage.total} pageSize={attendancePage.pageSize} onPage={attendancePage.setCurrentPage} label="Attendance pagination" /></> : <EmptyState title="No attendance recorded" message="Clock-in and clock-out events will appear here." />}
      </section>}
      {view === 'leave' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">LEAVE MANAGEMENT</span><h2>Leave requests</h2></div></div>
        {leavePage.total ? <><div className="table-scroll"><table><thead><tr><th>EMPLOYEE</th><th>TYPE</th><th>DATES</th><th>REASON</th><th>STATUS</th><th>REVIEW</th></tr></thead><tbody>
          {leavePage.pageItems.map((request) => <tr key={String(request.id)}><td>{String(request.employee_name ?? '')}</td><td>{String(request.leave_type ?? '')}</td><td>{String(request.starts_on ?? '')} – {String(request.ends_on ?? '')}</td><td>{String(request.reason ?? '')}</td><td>{String(request.status ?? '')}</td><td>{canLeave && request.status === 'pending' && <><button className="text-button" type="button" disabled={busy} onClick={() => void reviewLeave(Number(request.id), 'approved')}>Approve</button>{' · '}<button className="text-button" type="button" disabled={busy} onClick={() => void reviewLeave(Number(request.id), 'rejected')}>Reject</button></>}</td></tr>)}
        </tbody></table></div><Pagination page={leavePage.currentPage} totalPages={leavePage.totalPages} total={leavePage.total} pageSize={leavePage.pageSize} onPage={leavePage.setCurrentPage} label="Leave requests pagination" /></> : <EmptyState title="No leave requests" message="Staff leave activity will appear here." />}
      </section>}
    </div>
  )
}

function PayrollWorkspacePage({
  data,
  user,
  section,
  onRefresh,
}: {
  data: {
    runs: Paginated<PayrollRunRecord>
    branches: Branch[]
    employees: Paginated<EmployeeRecord>
    components: Record<string, unknown>[]
    commissions: Paginated<Record<string, unknown>>
  } | null
  user: AuthUser
  section?: string
  onRefresh: () => void
}) {
  const [selectedRun, setSelectedRun] = useState<{ run: PayrollRunRecord; lines: Record<string, unknown>[] } | null>(null)
  const [accountingExport, setAccountingExport] = useState<string | null>(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const canCreate = user.permissions.includes('payroll.runs.manage')
  const canApprove = user.permissions.includes('payroll.approve')
  const canConfigure = user.permissions.includes('payroll.config.manage')
  const canManageCommissions = user.permissions.includes('payroll.commission.manage')

  async function submitPayrollItem(
    event: FormEvent<HTMLFormElement>,
    endpoint: string,
    successMessage: string,
  ) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const payload = Object.fromEntries(form.entries())
    setBusy(true)
    setNotice('')
    try {
      await apiRequest(endpoint, { method: 'POST', body: JSON.stringify(payload) })
      setNotice(successMessage)
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function createRun(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/payroll/runs', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: Number(form.get('branch_id')),
          period_starts_on: form.get('period_starts_on'),
          period_ends_on: form.get('period_ends_on'),
        }),
      })
      setNotice('Draft payroll run calculated.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function loadRun(id: number) {
    setNotice('')
    try {
      setSelectedRun(await apiRequest<{ run: PayrollRunRecord; lines: Record<string, unknown>[] }>(`/payroll/runs/${id}`))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function runAction(id: number, action: 'approve' | 'pay' | 'accounting-export') {
    setBusy(true)
    setNotice('')
    try {
      if (action === 'accounting-export') {
        const exportData = await apiRequest(`/payroll/runs/${id}/accounting-export`)
        setAccountingExport(JSON.stringify(exportData, null, 2))
        setNotice('Accounting export is ready.')
      } else {
        const result = await apiRequest<PayrollRunRecord>(`/payroll/runs/${id}/${action}`, { method: 'POST' })
        setNotice(`Payroll run ${result.status}.`)
        onRefresh()
        if (selectedRun?.run.id === id) await loadRun(id)
      }
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const runs = data?.runs.data ?? []
  const view = section || 'runs'
  const runsPage = usePaginatedList(runs)
  const commissionsPage = usePaginatedList(data?.commissions.data ?? [])
  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">FINANCE OPERATIONS</span><h1>Payroll</h1><p className="muted">Review salary, approved commissions, payroll runs and accounting exports.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>↻ <span className="hide-small">Refresh</span></button>
      </div>
      {notice && <div className="notice" role="status">{notice}</div>}
      {accountingExport && <section className="panel clinical-template-admin"><div className="panel-heading"><h2>Accounting interface preview</h2><button className="text-button" type="button" onClick={() => setAccountingExport(null)}>Close</button></div><pre className="accounting-export-preview">{accountingExport}</pre></section>}
      {view === 'runs' && canCreate && <section className="panel clinical-template-admin">
        <div className="panel-heading"><div><span className="eyebrow">PAY PERIOD</span><h2>Calculate a draft run</h2></div></div>
        <form className="form-grid" onSubmit={(event) => void createRun(event)}>
          <label className="field">Branch<select name="branch_id" required defaultValue={String(user.branch_id ?? data?.branches[0]?.id ?? '')}>{data?.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label className="field">Period starts<input name="period_starts_on" type="date" required /></label>
          <label className="field">Period ends<input name="period_ends_on" type="date" required /></label>
          <button className="button button-primary" disabled={busy || !data?.branches.length}>Calculate payroll</button>
        </form>
      </section>}
      {view === 'components' && canConfigure && <details className="panel clinical-template-admin" open>
        <summary>Configure payroll component</summary>
        <form className="form-grid" onSubmit={(event) => void submitPayrollItem(event, '/payroll/components', 'Payroll component saved.')}>
          <label className="field">Component name<input name="name" required maxLength={120} /></label>
          <label className="field">Type<select name="component_type"><option value="fixed_addition">Fixed addition</option><option value="fixed_deduction">Fixed deduction</option><option value="commission_rate">Commission rate</option></select></label>
          <label className="field">Amount / rate<input name="amount" type="number" min="0" max="100000000" step="0.01" required /></label>
          <button className="button button-secondary" disabled={busy}>Save component</button>
        </form>
        {!!data?.components.length && <p className="muted">{data.components.map((component) => `${String(component.name)} (${String(component.component_type)}: ${String(component.amount)})`).join(' · ')}</p>}
      </details>}
      {view === 'components' && user.permissions.includes('payroll.config.manage') && <details className="panel clinical-template-admin" open>
        <summary>Set employee compensation</summary>
        <form className="form-grid" onSubmit={(event) => {
          const form = new FormData(event.currentTarget)
          event.preventDefault()
          const employeeId = form.get('employee_id')
          void apiRequest(`/hr/employees/${employeeId}/compensation`, {
            method: 'PUT',
            body: JSON.stringify({
              monthly_salary: Number(form.get('monthly_salary')),
              currency_code: form.get('currency_code'),
              effective_from: form.get('effective_from'),
            }),
          }).then(() => { setNotice('Compensation saved.'); onRefresh() })
            .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
        }}>
          <label className="field">Employee<select name="employee_id" required defaultValue=""><option value="" disabled>Select employee</option>{data?.employees.data.map((employee) => <option value={employee.id} key={employee.id}>{employee.name} · {employee.employee_number}</option>)}</select></label>
          <label className="field">Monthly salary<input name="monthly_salary" type="number" min="0" step="0.01" required /></label>
          <label className="field">Currency<input name="currency_code" defaultValue="KES" maxLength={3} required /></label>
          <label className="field">Effective from<input name="effective_from" type="date" required /></label>
          <button className="button button-secondary" disabled={busy}>Save compensation</button>
        </form>
      </details>}
      {view === 'commissions' && canManageCommissions && <section className="panel clinical-template-admin">
        <div className="panel-heading"><div><span className="eyebrow">VARIABLE PAY</span><h2>Commissions</h2></div></div>
        <form className="form-grid" onSubmit={(event) => void submitPayrollItem(event, '/payroll/commissions', 'Commission recorded as pending approval.')}>
          <label className="field">Employee<select name="employee_id" required defaultValue=""><option value="" disabled>Select employee</option>{data?.employees.data.map((employee) => <option value={employee.id} key={employee.id}>{employee.name}</option>)}</select></label>
          <label className="field">Earned on<input name="earned_on" type="date" required /></label>
          <label className="field">Amount<input name="amount" type="number" min="0.01" step="0.01" required /></label>
          <label className="field field-wide">Description<input name="description" maxLength={255} required /></label>
          <button className="button button-secondary" disabled={busy}>Record commission</button>
        </form>
        {commissionsPage.total > 0 && <><div className="table-scroll"><table><thead><tr><th>EMPLOYEE</th><th>EARNED</th><th>AMOUNT</th><th>STATUS</th><th /></tr></thead><tbody>
          {commissionsPage.pageItems.map((commission) => <tr key={String(commission.id)}><td>{String(commission.employee_name ?? '')}</td><td>{String(commission.earned_on ?? '')}</td><td>{Number(commission.amount).toLocaleString()}</td><td>{String(commission.status ?? '')}</td><td>{commission.status === 'pending' && <button className="text-button" type="button" disabled={busy} onClick={() => {
            void apiRequest(`/payroll/commissions/${commission.id}/approve`, { method: 'POST' })
              .then(() => { setNotice('Commission approved.'); onRefresh() })
              .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
          }}>Approve</button>}</td></tr>)}
        </tbody></table></div><Pagination page={commissionsPage.currentPage} totalPages={commissionsPage.totalPages} total={commissionsPage.total} pageSize={commissionsPage.pageSize} onPage={commissionsPage.setCurrentPage} label="Commissions pagination" /></>}
      </section>}
      {view === 'runs' && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">PAYROLL REGISTER</span><h2>Payroll runs</h2></div></div>
        {runsPage.total ? <><div className="table-scroll"><table><thead><tr><th>PERIOD</th><th>BRANCH</th><th>GROSS</th><th>DEDUCTIONS</th><th>NET</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
          {runsPage.pageItems.map((run) => <tr key={run.id}><td>{run.period_starts_on} – {run.period_ends_on}</td><td>{data?.branches.find((branch) => branch.id === run.branch_id)?.name ?? 'Assigned branch'}</td><td>{Number(run.gross_total).toLocaleString()}</td><td>{Number(run.deduction_total).toLocaleString()}</td><td><strong>{Number(run.net_total).toLocaleString()}</strong></td><td>{run.status}</td><td><button className="text-button" type="button" onClick={() => void loadRun(run.id)}>Payslip register</button>{canApprove && run.status === 'draft' && <> · <button className="text-button" type="button" disabled={busy} onClick={() => void runAction(run.id, 'approve')}>Approve</button></>}{canApprove && run.status === 'approved' && <> · <button className="text-button" type="button" disabled={busy} onClick={() => void runAction(run.id, 'pay')}>Mark paid</button></>}{run.status !== 'draft' && <> · <button className="text-button" type="button" disabled={busy} onClick={() => void runAction(run.id, 'accounting-export')}>Accounting export</button></>}</td></tr>)}
        </tbody></table></div><Pagination page={runsPage.currentPage} totalPages={runsPage.totalPages} total={runsPage.total} pageSize={runsPage.pageSize} onPage={runsPage.setCurrentPage} label="Payroll runs pagination" /></> : <EmptyState title="No payroll runs" message="Create a draft run after setting up employee compensation and approved commissions." />}
      </section>}
      {view === 'runs' && selectedRun && <section className="panel table-panel">
        <div className="panel-heading"><div><span className="eyebrow">PAYSLIPS</span><h2>{selectedRun.run.period_starts_on} – {selectedRun.run.period_ends_on}</h2></div><button className="text-button" type="button" onClick={() => setSelectedRun(null)}>Close</button></div>
        <div className="table-scroll"><table><thead><tr><th>EMPLOYEE</th><th>BASE</th><th>COMMISSION</th><th>ADDITIONS</th><th>DEDUCTIONS</th><th>NET PAY</th></tr></thead><tbody>
          {selectedRun.lines.map((line) => <tr key={String(line.id)}><td>{String(line.employee_name ?? '')} <small className="table-subtext">{String(line.employee_number ?? '')}</small></td><td>{Number(line.base_salary).toLocaleString()} {String(line.currency_code)}</td><td>{Number(line.commission_total).toLocaleString()}</td><td>{Number(line.addition_total).toLocaleString()}</td><td>{Number(line.deduction_total).toLocaleString()}</td><td><strong>{Number(line.net_amount).toLocaleString()}</strong></td></tr>)}
        </tbody></table></div>
      </section>}
    </div>
  )
}

function ClinicalCarePage({
  data,
  loading,
  user,
  branches,
  page,
  onPage,
  onRefresh,
}: {
  data: Paginated<ClinicalEncounterRecord> | null
  loading: boolean
  user: AuthUser
  branches: Branch[]
  page: number
  onPage: (page: number) => void
  onRefresh: () => void
}) {
  const [patients, setPatients] = useState<Pick<CustomerRecord, 'id' | 'name'>[]>([])
  const [templates, setTemplates] = useState<{ id: number; name: string; fields: { key: string; label: string; type: string; required?: boolean; options?: string[] }[] }[]>([])
  const [availableBranches, setAvailableBranches] = useState<Branch[]>([])
  const [workflowReport, setWorkflowReport] = useState<{ total: number; in_progress: number; completed: number; follow_ups_due: number; follow_ups_upcoming: number } | null>(null)
  const [selected, setSelected] = useState<ClinicalEncounterRecord | null>(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [customerSearch, setCustomerSearch] = useState('')
  const [templateId, setTemplateId] = useState('')
  const [assessment, setAssessment] = useState<Record<string, string | number | boolean>>({})
  const canManage = user.permissions.includes('clinical.manage')

  useEffect(() => {
    let active = true
    Promise.all([
      apiRequest<Paginated<Pick<CustomerRecord, 'id' | 'name'>>>('/clinical/patients'),
      apiRequest<typeof templates>('/clinical/assessment-templates'),
      apiRequest<Branch[]>('/clinical/branches'),
      user.permissions.includes('clinical.reports.view')
        ? apiRequest<typeof workflowReport>('/clinical/workflow-report')
        : Promise.resolve(null),
    ]).then(([customerResult, templateResult, branchResult, report]) => {
      if (!active) return
      setPatients(customerResult.data)
      setTemplates(templateResult)
      setAvailableBranches(branchResult)
      setWorkflowReport(report)
    }).catch((requestError: unknown) => {
      if (active) setNotice(getErrorMessage(requestError))
    })
    return () => { active = false }
  }, [user.permissions])

  const template = templates.find((item) => String(item.id) === templateId)
  const filteredPatients = patients.filter((patient) =>
    includesSearch([patient.name], customerSearch),
  )
  const branchChoices = branches.length ? branches : availableBranches

  async function createEncounter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const vitals: Record<string, number> = {}
    for (const key of ['heart_rate', 'temperature_c', 'oxygen_saturation', 'pain_score']) {
      const value = String(form.get(key) ?? '').trim()
      if (value) vitals[key] = Number(value)
    }
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/clinical/encounters', {
        method: 'POST',
        body: JSON.stringify({
          customer_id: Number(form.get('customer_id')),
          branch_id: Number(form.get('branch_id')),
          template_id: templateId ? Number(templateId) : null,
          assessment: templateId ? assessment : undefined,
          vitals,
          clinical_note: form.get('clinical_note') || null,
          treatment_plan: form.get('treatment_plan') || null,
          follow_up_at: form.get('follow_up_at') || null,
          follow_up_notes: form.get('follow_up_notes') || null,
        }),
      })
      formElement.reset()
      setAssessment({})
      setTemplateId('')
      setNotice('Encounter created.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function createAssessmentTemplate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    let fields: unknown
    try {
      fields = JSON.parse(String(form.get('fields') ?? ''))
    } catch {
      setNotice('Assessment fields must be valid JSON.')
      return
    }
    if (!Array.isArray(fields)) {
      setNotice('Assessment fields must be a JSON array.')
      return
    }
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/clinical/assessment-templates', {
        method: 'POST',
        body: JSON.stringify({
          name: form.get('name'),
          description: form.get('description') || null,
          fields,
        }),
      })
      setTemplates(await apiRequest<typeof templates>('/clinical/assessment-templates'))
      setNotice('Assessment template created.')
      formElement.reset()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function openEncounter(id: number) {
    setNotice('')
    try {
      const record = await apiRequest<ClinicalEncounterRecord & { amendments?: ClinicalEncounterRecord['amendments'] }>(`/clinical/encounters/${id}`)
      setSelected(record)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function saveEncounter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selected) return
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      const record = await apiRequest<ClinicalEncounterRecord>(`/clinical/encounters/${selected.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          reason: form.get('reason'),
          clinical_note: form.get('clinical_note') || null,
          treatment_plan: form.get('treatment_plan') || null,
          follow_up_at: form.get('follow_up_at') || null,
          follow_up_notes: form.get('follow_up_notes') || null,
        }),
      })
      setSelected(record)
      setNotice('Encounter updated and amendment recorded.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function completeEncounter() {
    if (!selected) return
    setBusy(true)
    setNotice('')
    try {
      const record = await apiRequest<ClinicalEncounterRecord>(`/clinical/encounters/${selected.id}/complete`, { method: 'POST' })
      setSelected(record)
      setNotice('Encounter completed.')
      onRefresh()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">PATIENT CARE</span><h1>Clinical care</h1><p className="muted">Document encounters, assessments, treatment plans and follow-ups.</p></div>
        <button className="button button-secondary" type="button" onClick={onRefresh}>↻ <span className="hide-small">Refresh</span></button>
      </div>
      {notice && <div className="notice" role="status">{notice}</div>}
      {workflowReport && <div className="clinical-summary" aria-label="Clinical workflow summary">
        <div><strong>{workflowReport.total}</strong><span>Total encounters</span></div>
        <div><strong>{workflowReport.in_progress}</strong><span>In progress</span></div>
        <div><strong>{workflowReport.completed}</strong><span>Completed</span></div>
        <div><strong>{workflowReport.follow_ups_due}</strong><span>Follow-ups due</span></div>
        <div><strong>{workflowReport.follow_ups_upcoming}</strong><span>Upcoming follow-ups</span></div>
      </div>}
      {user.permissions.includes('clinical.templates.manage') && <details className="panel clinical-template-admin">
        <summary>Add an assessment template</summary>
        <form className="form-grid" onSubmit={(event) => void createAssessmentTemplate(event)}>
          <label className="field">Template name<input name="name" required maxLength={160} /></label>
          <label className="field field-wide">Description<input name="description" maxLength={2000} /></label>
          <label className="field field-wide">Assessment fields (JSON array)
            <textarea name="fields" required rows={7} defaultValue={'[\n  {"key":"presenting_concern","label":"Presenting concern","type":"text","required":true},\n  {"key":"risk_level","label":"Risk level","type":"select","required":true,"options":["low","moderate","high"]}\n]'} />
          </label>
          <button className="button button-secondary" disabled={busy}>Save template</button>
        </form>
      </details>}
      <div className="clinical-layout">
        <section className="panel clinical-list-panel">
          <div className="panel-heading"><div><span className="eyebrow">RECORDS</span><h2>Recent encounters</h2></div></div>
          {loading ? <LoadingState compact /> : (data?.data.length ?? 0) > 0 ? (
            <div className="clinical-encounter-list">
              {data?.data.map((encounter) => (
                <button className={`clinical-encounter-item ${selected?.id === encounter.id ? 'selected' : ''}`} key={encounter.id} type="button" onClick={() => void openEncounter(encounter.id)}>
                  <strong>{encounter.customer.name}</strong>
                  <span>{encounter.branch.name} · {formatDate(encounter.started_at)}</span>
                  <StatusPill active={encounter.status === 'completed'} />
                </button>
              ))}
            </div>
          ) : <EmptyState title="No encounters yet" message="A patient's clinical visits will appear here." />}
          {data && <Pagination page={data.current_page ?? page} totalPages={data.last_page ?? 1} total={data.total} pageSize={data.per_page ?? 25} onPage={onPage} label="Clinical encounters pagination" />}
        </section>
        {selected ? (
          <section className="panel clinical-detail-panel">
            <div className="panel-heading"><div><span className="eyebrow">{selected.status === 'completed' ? 'COMPLETED ENCOUNTER' : 'IN-PROGRESS ENCOUNTER'}</span><h2>{selected.customer.name}</h2></div><button className="text-button" type="button" onClick={() => setSelected(null)}>Close</button></div>
            <p className="muted">{selected.branch.name} · Started {formatDate(selected.started_at)}</p>
            <div className="clinical-vitals-summary">{Object.entries(selected.vitals ?? {}).map(([key, value]) => <span className="permission-chip" key={key}>{key.replaceAll('_', ' ')}: {value}</span>)}</div>
            {selected.status === 'in_progress' && canManage ? (
              <form className="form-grid" onSubmit={(event) => void saveEncounter(event)}>
                <label className="field field-wide">Amendment reason<input name="reason" minLength={5} maxLength={500} required placeholder="Reason for the clinical record change" /></label>
                <label className="field field-wide">Clinical note<textarea name="clinical_note" defaultValue={selected.clinical_note ?? ''} rows={4} /></label>
                <label className="field field-wide">Treatment plan<textarea name="treatment_plan" defaultValue={selected.treatment_plan ?? ''} rows={3} /></label>
                <label className="field">Follow-up date<input name="follow_up_at" type="date" defaultValue={selected.follow_up_at?.slice(0, 10) ?? ''} /></label>
                <label className="field field-wide">Follow-up notes<textarea name="follow_up_notes" defaultValue={selected.follow_up_notes ?? ''} rows={2} /></label>
                <div className="form-actions"><button className="button button-secondary" disabled={busy}>Save amendment</button><button className="button button-primary" type="button" disabled={busy} onClick={() => void completeEncounter()}>Complete encounter</button></div>
              </form>
            ) : (
              <div className="clinical-record-copy"><h3>Clinical note</h3><p>{selected.clinical_note || 'No note recorded.'}</p><h3>Treatment plan</h3><p>{selected.treatment_plan || 'No treatment plan recorded.'}</p><h3>Follow-up</h3><p>{selected.follow_up_at ? `${formatDate(selected.follow_up_at)} · ${selected.follow_up_notes ?? ''}` : 'No follow-up scheduled.'}</p></div>
            )}
            {!!selected.amendments?.length && <div className="clinical-amendments"><h3>Amendment history</h3>{selected.amendments.map((amendment) => <p key={amendment.id}><strong>{amendment.user?.name ?? 'Staff'}:</strong> {amendment.reason} · {formatDate(amendment.created_at)}</p>)}</div>}
          </section>
        ) : canManage ? (
          <section className="panel clinical-detail-panel">
            <div className="panel-heading"><div><span className="eyebrow">NEW VISIT</span><h2>Start an encounter</h2></div></div>
            <form className="form-grid" onSubmit={(event) => void createEncounter(event)}>
              <label className="field field-wide">Find patient<input value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="Search patient name or contact" /></label>
              <label className="field">Patient<select name="customer_id" required defaultValue=""><option value="" disabled>Select patient</option>{filteredPatients.map((patient) => <option value={patient.id} key={patient.id}>{patient.name}</option>)}</select></label>
              <label className="field">Branch<select name="branch_id" required defaultValue={String(user.branch_id ?? branchChoices[0]?.id ?? '')}>{branchChoices.map((branch) => <option value={branch.id} key={branch.id}>{branch.name}</option>)}</select></label>
              <label className="field field-wide">Assessment template<select value={templateId} onChange={(event) => { setTemplateId(event.target.value); setAssessment({}) }}><option value="">No template</option>{templates.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label>
              {template?.fields.map((field) => (
                <label className="field" key={field.key}>{field.label}{field.type === 'select' ? (
                  <select required={field.required} value={String(assessment[field.key] ?? '')} onChange={(event) => setAssessment((current) => ({ ...current, [field.key]: event.target.value }))}><option value="">Select…</option>{(field.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}</select>
                ) : field.type === 'boolean' ? (
                  <input type="checkbox" checked={Boolean(assessment[field.key])} onChange={(event) => setAssessment((current) => ({ ...current, [field.key]: event.target.checked }))} />
                ) : (
                  <input type={field.type === 'number' ? 'number' : 'text'} required={field.required} value={String(assessment[field.key] ?? '')} onChange={(event) => setAssessment((current) => ({ ...current, [field.key]: field.type === 'number' ? Number(event.target.value) : event.target.value }))} />
                )}</label>
              ))}
              <div className="clinical-vitals-inputs">
                <label className="field">Heart rate<input name="heart_rate" type="number" min="20" max="250" /></label>
                <label className="field">Temperature °C<input name="temperature_c" type="number" min="25" max="45" step="0.1" /></label>
                <label className="field">Oxygen saturation %<input name="oxygen_saturation" type="number" min="0" max="100" /></label>
                <label className="field">Pain score<input name="pain_score" type="number" min="0" max="10" /></label>
              </div>
              <label className="field field-wide">Clinical note<textarea name="clinical_note" rows={3} /></label>
              <label className="field field-wide">Treatment plan<textarea name="treatment_plan" rows={3} /></label>
              <label className="field">Follow-up date<input name="follow_up_at" type="date" min={BOOKING_DATE_MIN} /></label>
              <label className="field field-wide">Follow-up notes<textarea name="follow_up_notes" rows={2} /></label>
              <button className="button button-primary" disabled={busy || branchChoices.length === 0 || filteredPatients.length === 0}>Create encounter</button>
            </form>
          </section>
        ) : <section className="panel clinical-detail-panel"><EmptyState title="Select an encounter" message="Choose a record to review its clinical details." /></section>}
      </div>
    </div>
  )
}

function AppointmentsPage({
  data,
  loading,
  user,
  branches,
  section,
  page,
  onPage,
  onRefresh,
}: {
  data: Paginated<AppointmentRecord> | null
  loading: boolean
  user: AuthUser
  branches: Branch[]
  section?: string
  page: number
  onPage: (page: number) => void
  onRefresh: () => void
}) {
  const [search, setSearch] = useState('')
  const [activeView, setActiveView] = useState<'appointments' | 'queue' | 'waitlist'>('appointments')
  const [bookingOpen, setBookingOpen] = useState(false)
  const [waitlistOpen, setWaitlistOpen] = useState(false)
  const [selectedBranchId, setSelectedBranchId] = useState(String(branches[0]?.id ?? user.branch_id ?? ''))
  const [queueEntries, setQueueEntries] = useState<QueueEntry[]>([])
  const [waitlistEntries, setWaitlistEntries] = useState<Paginated<WaitlistRecord> | null>(null)
  const [queueLoading, setQueueLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busyAppointmentId, setBusyAppointmentId] = useState<number | null>(null)
  const [callingNext, setCallingNext] = useState(false)
  const canCreate = user.permissions.includes('appointments.create')
  const canManage = user.permissions.includes('appointments.manage')
  const canViewQueue = user.permissions.includes('queue.view')
  const canManageQueue = user.permissions.includes('queue.manage')
  const canViewWaitlist = user.permissions.includes('waitlist.view')
  const canManageWaitlist = user.permissions.includes('waitlist.manage')
  const appointments = (data?.data ?? []).filter((appointment) => {
    const target = search.trim().toLowerCase()
    if (!target) return true
    const branchName = appointment.branch?.name ?? ''
    const serviceName = appointment.service?.name ?? ''
    const customerName = appointment.customer?.name ?? ''
    return [customerName, serviceName, branchName, appointment.status, appointment.appointment_number]
      .join(' ')
      .toLowerCase()
      .includes(target)
  })

  useEffect(() => {
    if (section === 'queue' || section === 'waitlist' || section === 'bookings') {
      setActiveView(section === 'bookings' ? 'appointments' : section)
    }
  }, [section])

  const loadQueue = useCallback(async () => {
    if (!selectedBranchId) return
    setQueueLoading(true)
    setError('')
    try {
      setQueueEntries(await apiRequest<QueueEntry[]>(`/queue?branch_id=${encodeURIComponent(selectedBranchId)}`))
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setQueueLoading(false)
    }
  }, [selectedBranchId])

  const loadWaitlist = useCallback(async () => {
    setError('')
    try {
      setWaitlistEntries(await apiRequest<Paginated<WaitlistRecord>>('/waitlist'))
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    }
  }, [])

  useEffect(() => {
    if (activeView === 'appointments') return
    const timer = window.setTimeout(() => {
      if (activeView === 'queue') void loadQueue()
      if (activeView === 'waitlist') void loadWaitlist()
    }, 0)
    return () => window.clearTimeout(timer)
  }, [activeView, loadQueue, loadWaitlist])

  async function transition(appointment: AppointmentRecord, action: string) {
    const reason = action === 'cancel' ? window.prompt('Please provide a cancellation reason:') : null
    if (action === 'cancel' && !reason?.trim()) return

    setBusyAppointmentId(appointment.id)
    setError('')
    try {
      await apiRequest(`/appointments/${appointment.id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ action, ...(reason ? { reason: reason.trim() } : {}) }),
      })
      onRefresh()
      if (activeView === 'queue') await loadQueue()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusyAppointmentId(null)
    }
  }

  async function recordDeposit(appointmentId: number) {
    setBusyAppointmentId(appointmentId)
    setError('')
    try {
      await apiRequest(`/appointments/${appointmentId}/deposit`, {
        method: 'POST',
        body: JSON.stringify({ status: 'paid' }),
      })
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusyAppointmentId(null)
    }
  }

  async function callNext() {
    if (!selectedBranchId) return
    setCallingNext(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<{ called?: boolean; message?: string }>('/queue/call-next', {
        method: 'POST',
        body: JSON.stringify({ branch_id: Number(selectedBranchId) }),
      })
      if (result.called === false) {
        setNotice(result.message ?? 'There are no waiting appointments in this queue.')
      }
      await loadQueue()
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setCallingNext(false)
    }
  }

  async function updateWaitlist(entry: WaitlistRecord, status: 'offered' | 'fulfilled' | 'cancelled') {
    setError('')
    try {
      await apiRequest(`/waitlist/${entry.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      })
      await loadWaitlist()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">BOOKING & QUEUE</span>
          <h1>Appointments</h1>
          <p className="muted">Track customer bookings, queue status, and service delivery across branches.</p>
        </div>
        <div className="appointments-heading-actions">
          {canCreate && <button className="button button-primary" type="button" onClick={() => setBookingOpen(true)}>＋ New booking</button>}
          {canManageWaitlist && <button className="button button-secondary" type="button" onClick={() => setWaitlistOpen(true)}>＋ Add to waitlist</button>}
        </div>
      </div>

      {error && <div className="notice error" role="alert">{error}</div>}
      {notice && <div className="notice" role="status">{notice}</div>}

      {activeView === 'queue' && canViewQueue ? (
        <section className="panel customer-directory">
          <div className="queue-toolbar">
            <div>
              <span className="eyebrow">TODAY’S VISITS</span>
              <h2>Appointment queue</h2>
            </div>
            <div className="appointments-heading-actions">
              <label className="queue-branch-select">
                <span className="sr-only">Queue branch</span>
                <select value={selectedBranchId} onChange={(event) => { setSelectedBranchId(event.target.value); setNotice('') }}>
                  {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                </select>
              </label>
              <button className="button button-secondary" type="button" onClick={() => void loadQueue()} disabled={queueLoading}>Refresh queue</button>
              {canManageQueue && <button className="button button-primary" type="button" onClick={() => void callNext()} disabled={callingNext || queueLoading}>{callingNext ? 'Calling…' : 'Call next'}</button>}
            </div>
          </div>
          {queueLoading ? <LoadingState compact /> : queueEntries.length ? (
            <div className="table-scroll">
              <table>
                <thead><tr><th>POSITION</th><th>CUSTOMER</th><th>SERVICE</th><th>RESOURCES</th><th>STATUS</th></tr></thead>
                <tbody>{queueEntries.map((entry) => (
                  <tr key={entry.id}>
                    <td><span className="queue-position">{entry.queue_position}</span></td>
                    <td><strong>{entry.appointment?.customer?.name ?? 'Customer'}</strong></td>
                    <td>{entry.appointment?.service?.name ?? 'Service'}</td>
                    <td>{entry.appointment?.resources?.map((resource) => resource.name).join(', ') || '—'}</td>
                    <td><span className={`appointment-status status-${entry.status}`}>{formatAction(entry.status)}</span></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          ) : <EmptyState title="Queue is clear" message="Checked-in appointments will appear here in arrival order." />}
        </section>
      ) : activeView === 'waitlist' && canViewWaitlist ? (
        <section className="panel customer-directory">
          {waitlistEntries?.data.length ? (
            <div className="table-scroll">
              <table>
                <thead><tr><th>CUSTOMER</th><th>SERVICE</th><th>BRANCH</th><th>PREFERRED DATE</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
                <tbody>{waitlistEntries.data.map((entry) => (
                  <tr key={entry.id}>
                    <td><strong>{entry.customer?.name ?? 'Customer'}</strong></td>
                    <td>{entry.service?.name ?? 'Service'}</td>
                    <td>{entry.branch?.name ?? 'Branch'}</td>
                    <td>{entry.preferred_date ? formatDate(entry.preferred_date) : 'Any date'}</td>
                    <td><span className={`appointment-status status-${entry.status}`}>{formatAction(entry.status)}</span></td>
                    <td><div className="appointment-actions">
                      {canManageWaitlist && entry.status === 'waiting' && <button className="text-button" type="button" onClick={() => void updateWaitlist(entry, 'offered')}>Offer slot</button>}
                      {canManageWaitlist && entry.status === 'offered' && <button className="text-button" type="button" onClick={() => void updateWaitlist(entry, 'fulfilled')}>Fulfilled</button>}
                      {canManageWaitlist && ['waiting', 'offered'].includes(entry.status) && <button className="text-button appointment-cancel" type="button" onClick={() => void updateWaitlist(entry, 'cancelled')}>Remove</button>}
                    </div></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          ) : <EmptyState title="Waitlist is clear" message="Customers waiting for an appointment will appear here." />}
        </section>
      ) : (
      <section className="panel customer-directory">
        <label className="customer-search">
          <span aria-hidden="true">⌕</span>
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search appointment, customer, service or branch"
          />
        </label>

        {loading && !data ? <LoadingState /> : appointments.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>APPOINTMENT</th>
                  <th>CUSTOMER</th>
                  <th>SERVICE</th>
                  <th>BRANCH</th>
                  <th>TIME</th>
                  <th>STATUS</th>
                  <th>ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                {appointments.map((appointment) => (
                  <tr key={appointment.id}>
                    <td>
                      <span className="table-entity">
                        <span className="table-icon sage">◷</span>
                        <span>
                          <strong>{appointment.appointment_number}</strong>
                          <small className="table-subtext">{appointment.booking_channel}</small>
                        </span>
                      </span>
                    </td>
                    <td>
                      <span className="table-entity">
                        <span className="profile-avatar small">{initials(appointment.customer?.name ?? 'Customer')}</span>
                        <span>
                          <strong>{appointment.customer?.name ?? 'Guest customer'}</strong>
                          <small className="table-subtext">{appointment.customer?.phone ?? 'No phone on file'}</small>
                        </span>
                      </span>
                    </td>
                    <td>
                      <strong>{appointment.service?.name ?? 'Service'}</strong>
                      <small className="table-subtext">{appointment.service?.duration_minutes ?? 0} min</small>
                    </td>
                    <td>{appointment.branch?.name ?? branches[0]?.name ?? 'Branch'}</td>
                    <td>
                      <strong>{formatAppointmentTime(appointment.starts_at, true)}</strong>
                      <small className="table-subtext">{formatAppointmentTime(appointment.ends_at)}</small>
                    </td>
                    <td><span className={`appointment-status status-${appointment.status}`}>{formatAction(appointment.status)}</span></td>
                    <td><div className="appointment-actions">
                      {canManage && appointment.status === 'confirmed' && <button className="text-button" type="button" disabled={busyAppointmentId === appointment.id} onClick={() => void transition(appointment, 'check_in')}>Check in</button>}
                      {canManage && appointment.status === 'checked_in' && <button className="text-button" type="button" disabled={busyAppointmentId === appointment.id} onClick={() => void transition(appointment, 'start')}>Start</button>}
                      {canManage && appointment.status === 'in_service' && <button className="text-button" type="button" disabled={busyAppointmentId === appointment.id} onClick={() => void transition(appointment, 'complete')}>Complete</button>}
                      {user.permissions.includes('appointments.deposit.manage') && appointment.deposit_status === 'pending' && <button className="text-button" type="button" disabled={busyAppointmentId === appointment.id} onClick={() => void recordDeposit(appointment.id)}>Mark deposit paid</button>}
                      {canManage && ['confirmed', 'awaiting_deposit'].includes(appointment.status) && <button className="text-button appointment-cancel" type="button" disabled={busyAppointmentId === appointment.id} onClick={() => void transition(appointment, 'cancel')}>Cancel</button>}
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState title={search ? 'No matching appointments' : 'No appointments yet'} message={search ? 'Try another customer, service or branch search.' : 'Booking activity will appear here when the team starts scheduling visits.'} />
        )}
        {!search && <Pagination page={data?.current_page ?? page} totalPages={data?.last_page ?? 1} total={data?.total ?? appointments.length} pageSize={data?.per_page ?? 50} onPage={onPage} label="Appointments pagination" />}
      </section>
      )}
      {bookingOpen && <AppointmentBookingDialog user={user} branches={branches} onClose={() => setBookingOpen(false)} onSaved={() => { setBookingOpen(false); onRefresh() }} />}
      {waitlistOpen && <WaitlistDialog user={user} branches={branches} onClose={() => setWaitlistOpen(false)} onSaved={() => { setWaitlistOpen(false); void loadWaitlist() }} />}
    </div>
  )
}

type WaitlistRecord = {
  id: number
  status: string
  preferred_date: string | null
  branch?: Pick<Branch, 'id' | 'name'> | null
  service?: Pick<ServiceRecord, 'id' | 'name'> | null
  customer?: Pick<CustomerRecord, 'id' | 'name' | 'phone'> | null
}

type QueueEntry = {
  id: number
  queue_position: number
  status: string
  appointment?: {
    id: number
    customer?: Pick<CustomerRecord, 'name'> | null
    service?: Pick<ServiceRecord, 'name'> | null
    resources?: { id: number; name: string }[]
  } | null
}

function WaitlistDialog({
  user,
  branches,
  onClose,
  onSaved,
}: {
  user: AuthUser
  branches: Branch[]
  onClose: () => void
  onSaved: () => void
}) {
  const [branchId, setBranchId] = useState(String(branches[0]?.id ?? user.branch_id ?? ''))
  const [services, setServices] = useState<ServiceRecord[]>([])
  const [customers, setCustomers] = useState<CustomerRecord[]>([])
  const [customerSearch, setCustomerSearch] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!branchId) return
    let active = true
    void apiRequest<ServiceRecord[]>(`/services?branch_id=${encodeURIComponent(branchId)}`)
      .then((result) => { if (active) setServices(result) })
      .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
    return () => { active = false }
  }, [branchId])

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      void apiRequest<Paginated<CustomerRecord>>(`/customers${customerSearch.trim() ? `?search=${encodeURIComponent(customerSearch.trim())}` : ''}`)
        .then((result) => { if (active) setCustomers(result.data) })
        .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
    }, 200)
    return () => { active = false; window.clearTimeout(timer) }
  }, [customerSearch])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest('/waitlist', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: Number(branchId),
          service_id: Number(form.get('service_id')),
          customer_id: Number(form.get('customer_id')),
          preferred_date: String(form.get('preferred_date') ?? '') || null,
          notes: String(form.get('notes') ?? '') || null,
        }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="waitlist-title">
        <div className="dialog-heading"><div><span className="eyebrow">APPOINTMENT WAITLIST</span><h2 id="waitlist-title">Add a customer</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {!branches.length ? <EmptyState title="No branch available" message="Your account needs an assigned branch before adding a customer to the waitlist." /> : (
          <form className="customer-form" onSubmit={(event) => void submit(event)}>
            <label>Branch<select value={branchId} onChange={(event) => setBranchId(event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
            <label>Customer search<input value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="Search customer name, email or phone" /></label>
            <label>Customer<select name="customer_id" required><option value="">Select a customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}{customer.phone ? ` · ${customer.phone}` : ''}</option>)}</select></label>
            <label>Service<select name="service_id" required><option value="">Select a service</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</select></label>
            <label>Preferred date (optional)<input type="date" name="preferred_date" min={BOOKING_DATE_MIN} /></label>
            <label>Notes<textarea name="notes" rows={3} maxLength={1000} /></label>
            <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Adding…' : 'Add to waitlist'}</button></div>
          </form>
        )}
      </section>
    </div>
  )
}

function AppointmentBookingDialog({
  user,
  branches,
  customer,
  onClose,
  onSaved,
}: {
  user: AuthUser
  branches: Branch[]
  customer?: Pick<CustomerRecord, 'id' | 'name'>
  onClose: () => void
  onSaved: () => void
}) {
  const [branchId, setBranchId] = useState(String(branches[0]?.id ?? user.branch_id ?? ''))
  const [services, setServices] = useState<ServiceRecord[]>([])
  const [customers, setCustomers] = useState<CustomerRecord[]>([])
  const [customerSearch, setCustomerSearch] = useState('')
  const [serviceId, setServiceId] = useState('')
  const [customerId, setCustomerId] = useState(customer ? String(customer.id) : '')
  const [date, setDate] = useState(BOOKING_DATE_MIN)
  const [slots, setSlots] = useState<{ starts_at: string; ends_at: string; resources: { id: number; name: string; resource_type: string }[] }[]>([])
  const [selectedSlot, setSelectedSlot] = useState('')
  const [loadingServices, setLoadingServices] = useState(true)
  const [loadingCustomers, setLoadingCustomers] = useState(!customer)
  const [loadingSlots, setLoadingSlots] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!branchId) return
    let active = true
    void apiRequest<ServiceRecord[]>(`/services?branch_id=${encodeURIComponent(branchId)}`)
      .then((result) => {
        if (!active) return
        setServices(result)
      })
      .catch((requestError: unknown) => {
        if (active) setError(getErrorMessage(requestError))
      })
      .finally(() => { if (active) setLoadingServices(false) })
    return () => { active = false }
  }, [branchId])

  useEffect(() => {
    if (customer) return
    let active = true
    const timer = window.setTimeout(() => {
      void apiRequest<Paginated<CustomerRecord>>(`/customers${customerSearch.trim() ? `?search=${encodeURIComponent(customerSearch.trim())}` : ''}`)
        .then((result) => { if (active) setCustomers(result.data) })
        .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
        .finally(() => { if (active) setLoadingCustomers(false) })
    }, 200)
    return () => { active = false; window.clearTimeout(timer) }
  }, [customer, customerSearch])

  useEffect(() => {
    if (!branchId || !serviceId || !date) {
      return
    }
    let active = true
    void apiRequest<{ slots: typeof slots }>(`/availability?branch_id=${encodeURIComponent(branchId)}&service_id=${encodeURIComponent(serviceId)}&date=${encodeURIComponent(date)}`)
      .then((result) => { if (active) setSlots(result.slots) })
      .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
      .finally(() => { if (active) setLoadingSlots(false) })
    return () => { active = false }
  }, [branchId, serviceId, date])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const slot = slots.find((candidate) => candidate.starts_at === selectedSlot)
    if (!slot) {
      setError('Choose an available appointment time.')
      return
    }
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest('/appointments', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: Number(branchId),
          service_id: Number(serviceId),
          customer_id: Number(customer?.id ?? customerId),
          starts_at: slot.starts_at,
          resource_ids: slot.resources.map((resource) => resource.id),
          notes: String(form.get('notes') ?? '') || null,
        }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="booking-title">
        <div className="dialog-heading"><div><span className="eyebrow">STAFF BOOKING</span><h2 id="booking-title">Book an appointment</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {!branches.length ? <EmptyState title="No branch available" message="Your account needs an assigned branch before an appointment can be booked." /> : (
          <form className="customer-form" onSubmit={(event) => void submit(event)}>
            <label>Branch<select value={branchId} onChange={(event) => { setBranchId(event.target.value); setServiceId(''); setSelectedSlot(''); setSlots([]); setLoadingServices(true); setLoadingSlots(false); setError('') }}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
            {customer ? <label>Customer<input value={customer.name} disabled /></label> : <>
              <label>Customer search<input value={customerSearch} onChange={(event) => { setCustomerSearch(event.target.value); setLoadingCustomers(true); setError('') }} placeholder="Search customer name, email or phone" /></label>
              <label>Customer<select required value={customerId} onChange={(event) => setCustomerId(event.target.value)}><option value="">{loadingCustomers ? 'Loading customers…' : 'Select a customer'}</option>{customers.map((option) => <option key={option.id} value={option.id}>{option.name}{option.phone ? ` · ${option.phone}` : ''}</option>)}</select></label>
            </>}
            <label>Service<select required value={serviceId} onChange={(event) => { setServiceId(event.target.value); setSelectedSlot(''); setSlots([]); setLoadingSlots(Boolean(event.target.value)); setError('') }}><option value="">{loadingServices ? 'Loading services…' : 'Select a service'}</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name} · {service.duration_minutes} min</option>)}</select></label>
            <label>Date<input type="date" required min={BOOKING_DATE_MIN} max={BOOKING_DATE_MAX} value={date} onChange={(event) => { setDate(event.target.value); setSelectedSlot(''); setSlots([]); setLoadingSlots(Boolean(serviceId)); setError('') }} /></label>
            <label>Available time<select required value={selectedSlot} onChange={(event) => setSelectedSlot(event.target.value)} disabled={loadingSlots || !slots.length}><option value="">{loadingSlots ? 'Finding times…' : slots.length ? 'Select an available time' : 'No available times for this date'}</option>{slots.map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{formatAppointmentTime(slot.starts_at)} · {slot.resources.map((resource) => resource.name).join(', ')}</option>)}</select></label>
            <label>Booking notes<textarea name="notes" rows={3} maxLength={2000} /></label>
            <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy || loadingServices || loadingCustomers || loadingSlots}>{busy ? 'Booking…' : 'Confirm booking'}</button></div>
          </form>
        )}
      </section>
    </div>
  )
}

function AppointmentCancelDialog({
  appointment,
  onClose,
  onSaved,
}: {
  appointment: CustomerActivityAppointment
  onClose: () => void
  onSaved: () => void
}) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await apiRequest(`/appointments/${appointment.id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'cancel', reason: reason.trim() }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="appointment-cancel-title">
        <div className="dialog-heading"><div><span className="eyebrow">APPOINTMENT</span><h2 id="appointment-cancel-title">Cancel appointment</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{appointment.appointment_number} · {appointment.service_name ?? 'Appointment'} · {formatDate(appointment.starts_at)}</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Cancellation reason<textarea value={reason} onChange={(event) => setReason(event.target.value)} required minLength={3} maxLength={500} rows={3} /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Keep appointment</button><button className="button button-primary danger-button" type="submit" disabled={busy || reason.trim().length < 3}>{busy ? 'Cancelling…' : 'Cancel appointment'}</button></div>
        </form>
      </section>
    </div>
  )
}

function AppointmentDepositDialog({
  appointment,
  onClose,
  onSaved,
}: {
  appointment: CustomerActivityAppointment
  onClose: () => void
  onSaved: () => void
}) {
  const [status, setStatus] = useState<'paid' | 'waived'>('paid')
  const [reference, setReference] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await apiRequest(`/appointments/${appointment.id}/deposit`, {
        method: 'POST',
        body: JSON.stringify({ status, reference: reference.trim() || null }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="appointment-deposit-title">
        <div className="dialog-heading"><div><span className="eyebrow">DEPOSIT</span><h2 id="appointment-deposit-title">Record deposit</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{appointment.appointment_number} · {formatMoneyValue(appointment.deposit_due, appointment.currency_code)} due</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Outcome<select value={status} onChange={(event) => setStatus(event.target.value as 'paid' | 'waived')}><option value="paid">Deposit paid</option><option value="waived">Deposit waived</option></select></label>
          <label>Payment reference (optional)<input value={reference} onChange={(event) => setReference(event.target.value)} maxLength={120} placeholder="M-Pesa, card or receipt reference" /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Record deposit'}</button></div>
        </form>
      </section>
    </div>
  )
}

function AppointmentRescheduleDialog({
  appointment,
  onClose,
  onSaved,
}: {
  appointment: CustomerActivityAppointment
  onClose: () => void
  onSaved: () => void
}) {
  const [date, setDate] = useState(BOOKING_DATE_MIN)
  const [slots, setSlots] = useState<{ starts_at: string; ends_at: string; resources: { id: number; name: string; resource_type: string }[] }[]>([])
  const [selectedSlot, setSelectedSlot] = useState('')
  const [loadingSlots, setLoadingSlots] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    void apiRequest<{ slots: typeof slots }>(`/availability?branch_id=${encodeURIComponent(String(appointment.branch_id))}&service_id=${encodeURIComponent(String(appointment.service_id))}&date=${encodeURIComponent(date)}`)
      .then((result) => { if (active) setSlots(result.slots) })
      .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
      .finally(() => { if (active) setLoadingSlots(false) })
    return () => { active = false }
  }, [appointment.branch_id, appointment.service_id, date])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const slot = slots.find((candidate) => candidate.starts_at === selectedSlot)
    if (!slot) {
      setError('Choose an available appointment time.')
      return
    }
    setBusy(true)
    setError('')
    try {
      await apiRequest(`/appointments/${appointment.id}/reschedule`, {
        method: 'POST',
        body: JSON.stringify({ starts_at: slot.starts_at, resource_ids: slot.resources.map((resource) => resource.id) }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="appointment-reschedule-title">
        <div className="dialog-heading"><div><span className="eyebrow">APPOINTMENT</span><h2 id="appointment-reschedule-title">Reschedule appointment</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{appointment.appointment_number} · {appointment.service_name ?? 'Appointment'} · {appointment.branch_name ?? 'Branch'}</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>New date<input type="date" required min={BOOKING_DATE_MIN} max={BOOKING_DATE_MAX} value={date} onChange={(event) => { setDate(event.target.value); setSelectedSlot(''); setSlots([]); setLoadingSlots(true); setError('') }} /></label>
          <label>Available time<select required value={selectedSlot} onChange={(event) => setSelectedSlot(event.target.value)} disabled={loadingSlots || !slots.length}><option value="">{loadingSlots ? 'Finding times…' : slots.length ? 'Select an available time' : 'No available times for this date'}</option>{slots.map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{formatAppointmentTime(slot.starts_at)} · {slot.resources.map((resource) => resource.name).join(', ')}</option>)}</select></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy || loadingSlots || !selectedSlot}>{busy ? 'Rescheduling…' : 'Reschedule appointment'}</button></div>
        </form>
      </section>
    </div>
  )
}

function PosRefundDialog({
  sale,
  onClose,
  onSaved,
}: {
  sale: CustomerActivitySale
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [reason, setReason] = useState('')
  const [tenderType, setTenderType] = useState<'cash' | 'mpesa' | 'card'>('cash')
  const [tenderReference, setTenderReference] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const result = await apiRequest<{ refund_number: string }>(`/pos/sales/${sale.id}/refund`, {
        method: 'POST',
        body: JSON.stringify({
          reason: reason.trim(),
          tender_type: tenderType,
          tender_reference: tenderType === 'cash' ? null : tenderReference.trim() || null,
        }),
      })
      onSaved(`Refund ${result.refund_number} processed for ${sale.receipt_number}.`)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="pos-refund-title">
        <div className="dialog-heading"><div><span className="eyebrow">POS REFUND</span><h2 id="pos-refund-title">Refund sale</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{sale.receipt_number} · {sale.branch_name ?? 'Branch'} · {formatMoneyValue(sale.total_amount, sale.currency_code)}</p>
        <p className="form-hint">Refunds require an open till shift at this branch that was opened by you. Stock, loyalty points, and accounting entries are reversed automatically.</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Refund reason<textarea value={reason} onChange={(event) => setReason(event.target.value)} required minLength={3} maxLength={1000} rows={3} /></label>
          <label>Refund tender<select value={tenderType} onChange={(event) => setTenderType(event.target.value as 'cash' | 'mpesa' | 'card')}><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option></select></label>
          {tenderType !== 'cash' && <label>External refund reference<input value={tenderReference} onChange={(event) => setTenderReference(event.target.value)} required maxLength={120} placeholder="Provider confirmation reference" /></label>}
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary danger-button" type="submit" disabled={busy || reason.trim().length < 3 || (tenderType !== 'cash' && !tenderReference.trim())}>{busy ? 'Refunding…' : 'Refund sale'}</button></div>
        </form>
      </section>
    </div>
  )
}

function ClinicalEncounterDialog({
  mode,
  customerId,
  customerName,
  branches,
  appointments,
  encounter,
  onClose,
  onSaved,
}: {
  mode: 'create' | 'amend'
  customerId: number
  customerName: string
  branches: Branch[]
  appointments: CustomerActivityAppointment[]
  encounter?: CustomerActivityEncounter
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [branchId, setBranchId] = useState(String(encounter?.branch_id ?? branches[0]?.id ?? ''))
  const [appointmentId, setAppointmentId] = useState('')
  const [templates, setTemplates] = useState<ClinicalAssessmentTemplateRecord[]>([])
  const [loadingTemplates, setLoadingTemplates] = useState(mode === 'create')
  const [templateId, setTemplateId] = useState('')
  const [assessment, setAssessment] = useState<Record<string, string | number | boolean>>({})
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const template = templates.find((item) => String(item.id) === templateId)
  const existingVitals = (encounter?.vitals && typeof encounter.vitals === 'object' ? encounter.vitals : {}) as Record<string, unknown>
  const vitalValue = (key: string) => existingVitals[key] === undefined || existingVitals[key] === null ? '' : String(existingVitals[key])
  const appointmentOptions = appointments.filter((item) => String(item.branch_id) === branchId && !['cancelled', 'no_show', 'rescheduled'].includes(item.status))

  useEffect(() => {
    if (mode !== 'create') return
    let active = true
    void apiRequest<ClinicalAssessmentTemplateRecord[]>('/clinical/assessment-templates')
      .then((result) => { if (active) setTemplates(result) })
      .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
      .finally(() => { if (active) setLoadingTemplates(false) })
    return () => { active = false }
  }, [mode])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const vitals: Record<string, string | number> = {}
    for (const key of ['systolic_bp', 'diastolic_bp', 'heart_rate', 'temperature_c', 'respiratory_rate', 'oxygen_saturation', 'weight_kg', 'height_cm', 'pain_score']) {
      const value = String(form.get(key) ?? '').trim()
      if (value) vitals[key] = Number(value)
    }
    const notes = String(form.get('vitals_notes') ?? '').trim()
    if (notes) vitals.notes = notes
    const fields = {
      clinical_note: String(form.get('clinical_note') ?? '') || null,
      treatment_plan: String(form.get('treatment_plan') ?? '') || null,
      follow_up_at: String(form.get('follow_up_at') ?? '') || null,
      follow_up_notes: String(form.get('follow_up_notes') ?? '') || null,
    }
    setBusy(true)
    setError('')
    try {
      if (mode === 'amend' && encounter) {
        await apiRequest(`/clinical/encounters/${encounter.id}`, {
          method: 'PUT',
          body: JSON.stringify({ reason: reason.trim(), vitals, ...fields }),
        })
        onSaved('Clinical encounter updated and amendment recorded.')
      } else {
        const assessmentPayload: Record<string, string | number | boolean> = {}
        for (const field of template?.fields ?? []) {
          const value = assessment[field.key]
          if (typeof value === 'boolean') assessmentPayload[field.key] = value
          else if (value !== undefined && String(value) !== '') assessmentPayload[field.key] = field.type === 'number' ? Number(value) : value
        }
        await apiRequest('/clinical/encounters', {
          method: 'POST',
          body: JSON.stringify({
            customer_id: customerId,
            branch_id: Number(branchId),
            appointment_id: appointmentId ? Number(appointmentId) : null,
            template_id: templateId ? Number(templateId) : null,
            assessment: templateId ? assessmentPayload : undefined,
            vitals,
            ...fields,
          }),
        })
        onSaved('Clinical encounter created.')
      }
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog customer-clinical-dialog" role="dialog" aria-modal="true" aria-labelledby="clinical-encounter-title">
        <div className="dialog-heading"><div><span className="eyebrow">CLINICAL RECORD</span><h2 id="clinical-encounter-title">{mode === 'amend' ? 'Amend encounter' : 'New clinical encounter'}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{customerName}{encounter ? ` · Started ${formatDate(encounter.started_at)}` : ''}</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          {mode === 'create' ? <>
            <div className="customer-form-grid">
              <label>Branch<select required value={branchId} onChange={(event) => { setBranchId(event.target.value); setAppointmentId('') }}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
              <label>Linked appointment (optional)<select value={appointmentId} onChange={(event) => setAppointmentId(event.target.value)}><option value="">No appointment link</option>{appointmentOptions.map((item) => <option key={item.id} value={item.id}>{item.appointment_number} · {formatDate(item.starts_at)}</option>)}</select></label>
            </div>
            <label>Assessment template<select value={templateId} onChange={(event) => { setTemplateId(event.target.value); setAssessment({}) }}><option value="">{loadingTemplates ? 'Loading templates…' : 'No template'}</option>{templates.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            {template && <div className="customer-form-grid">{template.fields.map((field) => (
              <label key={field.key}>{field.label}
                {field.type === 'select' ? (
                  <select required={field.required} value={String(assessment[field.key] ?? '')} onChange={(event) => setAssessment((current) => ({ ...current, [field.key]: event.target.value }))}><option value="">Select…</option>{(field.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}</select>
                ) : field.type === 'boolean' ? (
                  <input type="checkbox" checked={Boolean(assessment[field.key])} onChange={(event) => setAssessment((current) => ({ ...current, [field.key]: event.target.checked }))} />
                ) : (
                  <input type={field.type === 'number' ? 'number' : 'text'} required={field.required} value={String(assessment[field.key] ?? '')} onChange={(event) => setAssessment((current) => ({ ...current, [field.key]: field.type === 'number' ? Number(event.target.value) : event.target.value }))} />
                )}
              </label>
            ))}</div>}
          </> : <p className="form-hint">Branch: {encounter?.branch_name ?? 'Assigned branch'}. Every amendment is attributed and keeps the previous clinical state for audit.</p>}
          <div className="customer-form-grid">
            <label>Systolic BP<input name="systolic_bp" type="number" min="40" max="300" defaultValue={vitalValue('systolic_bp')} /></label>
            <label>Diastolic BP<input name="diastolic_bp" type="number" min="20" max="200" defaultValue={vitalValue('diastolic_bp')} /></label>
            <label>Heart rate<input name="heart_rate" type="number" min="20" max="250" defaultValue={vitalValue('heart_rate')} /></label>
            <label>Temperature °C<input name="temperature_c" type="number" min="25" max="45" step="0.1" defaultValue={vitalValue('temperature_c')} /></label>
            <label>Respiratory rate<input name="respiratory_rate" type="number" min="4" max="80" defaultValue={vitalValue('respiratory_rate')} /></label>
            <label>Oxygen saturation %<input name="oxygen_saturation" type="number" min="0" max="100" defaultValue={vitalValue('oxygen_saturation')} /></label>
            <label>Weight kg<input name="weight_kg" type="number" min="0.1" max="500" step="0.1" defaultValue={vitalValue('weight_kg')} /></label>
            <label>Height cm<input name="height_cm" type="number" min="20" max="280" step="0.1" defaultValue={vitalValue('height_cm')} /></label>
            <label>Pain score<input name="pain_score" type="number" min="0" max="10" defaultValue={vitalValue('pain_score')} /></label>
          </div>
          <label>Vital notes<input name="vitals_notes" maxLength={2000} defaultValue={vitalValue('notes')} /></label>
          {mode === 'amend' && encounter?.assessment !== null && encounter?.assessment !== undefined && <details className="customer-clinical-json"><summary>Recorded assessment</summary><pre>{JSON.stringify(encounter.assessment, null, 2)}</pre></details>}
          <label>Clinical note<textarea name="clinical_note" rows={4} maxLength={20000} defaultValue={encounter?.clinical_note ?? ''} /></label>
          <label>Treatment plan<textarea name="treatment_plan" rows={4} maxLength={20000} defaultValue={encounter?.treatment_plan ?? ''} placeholder="Products, in-clinic treatments, cadence and home care" /></label>
          <div className="customer-form-grid">
            <label>Follow-up date<input name="follow_up_at" type="date" min={BOOKING_DATE_MIN} defaultValue={encounter?.follow_up_at?.slice(0, 10) ?? ''} /></label>
            <label>Follow-up notes<input name="follow_up_notes" maxLength={5000} defaultValue={encounter?.follow_up_notes ?? ''} /></label>
          </div>
          {mode === 'amend' && <label>Amendment reason<input value={reason} onChange={(event) => setReason(event.target.value)} required minLength={5} maxLength={500} placeholder="Explain why the clinical record is changing" /></label>}
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy || (mode === 'amend' && reason.trim().length < 5)}>{busy ? 'Saving…' : mode === 'amend' ? 'Save amendment' : 'Create encounter'}</button></div>
        </form>
      </section>
    </div>
  )
}

function ClinicalCompleteDialog({
  encounter,
  onClose,
  onSaved,
}: {
  encounter: CustomerActivityEncounter
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await apiRequest(`/clinical/encounters/${encounter.id}/complete`, {
        method: 'POST',
        body: JSON.stringify({ completion_note: note.trim() || null }),
      })
      onSaved('Clinical encounter completed.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="clinical-complete-title">
        <div className="dialog-heading"><div><span className="eyebrow">CLINICAL RECORD</span><h2 id="clinical-complete-title">Complete encounter</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">Started {formatDate(encounter.started_at)} · {encounter.branch_name ?? 'Branch'}</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Completion note (optional)<textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} maxLength={5000} placeholder="Outcome, aftercare advice or handover notes" /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Completing…' : 'Complete encounter'}</button></div>
        </form>
      </section>
    </div>
  )
}

function LoyaltyAdjustDialog({
  customerId,
  customerName,
  balance,
  onClose,
  onSaved,
}: {
  customerId: number
  customerName: string
  balance: number
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [points, setPoints] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await apiRequest('/website/loyalty/adjustments', {
        method: 'POST',
        body: JSON.stringify({ customer_id: customerId, points: Number(points), reason: reason.trim() }),
      })
      onSaved('Rewards balance adjusted.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="loyalty-adjust-title">
        <div className="dialog-heading"><div><span className="eyebrow">REWARDS</span><h2 id="loyalty-adjust-title">Adjust points</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{customerName} · current balance {balance.toLocaleString()} pts</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Points adjustment<input type="number" required step="1" min="-1000000" max="1000000" value={points} onChange={(event) => setPoints(event.target.value)} placeholder="Use a negative number to deduct points" /></label>
          <label>Reason<textarea value={reason} onChange={(event) => setReason(event.target.value)} required minLength={3} maxLength={500} rows={3} /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy || !points || Number(points) === 0 || reason.trim().length < 3}>{busy ? 'Adjusting…' : 'Apply adjustment'}</button></div>
        </form>
      </section>
    </div>
  )
}

function PaymentSettlementDialog({
  payment,
  onClose,
  onSaved,
}: {
  payment: CustomerActivityPayment
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    try {
      const result = await apiRequest<{ status: string }>(`/payments/reconciliation/${payment.transaction_id}/settlement`, {
        method: 'POST',
        body: JSON.stringify({
          statement_reference: String(form.get('statement_reference') ?? '').trim(),
          settled_amount: String(form.get('settled_amount') ?? ''),
          fee_amount: String(form.get('fee_amount') ?? '0') || '0',
          clearing_account_code: String(form.get('clearing_account_code') ?? '').trim(),
          settled_at: String(form.get('settled_at') ?? ''),
        }),
      })
      onSaved(result.status === 'matched' ? 'Settlement recorded and matched.' : 'Settlement recorded with a variance for review.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="payment-settlement-title">
        <div className="dialog-heading"><div><span className="eyebrow">PAYMENT RECONCILIATION</span><h2 id="payment-settlement-title">Record settlement</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <p className="muted">{payment.order_number ?? payment.reference ?? 'Online payment'} · {formatMoneyValue(payment.amount, payment.currency_code)} · {payment.provider.replaceAll('_', ' ')}</p>
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <div className="customer-form-grid">
            <label>Statement reference<input name="statement_reference" required maxLength={160} placeholder="Provider settlement statement" /></label>
            <label>Settled at<input name="settled_at" type="date" required defaultValue={BOOKING_DATE_MIN} /></label>
            <label>Settled amount<input name="settled_amount" type="number" required min="0" step="0.01" defaultValue={Number(payment.amount).toFixed(2)} /></label>
            <label>Provider fee<input name="fee_amount" type="number" min="0" step="0.01" defaultValue="0.00" /></label>
          </div>
          <label>Clearing account code<input name="clearing_account_code" required maxLength={80} placeholder="Bank or clearing account code" /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Recording…' : 'Record settlement'}</button></div>
        </form>
      </section>
    </div>
  )
}

function ServiceCataloguePage({
  data,
  loading,
  user,
  branches,
  section,
  onRefresh,
}: {
  data: ServiceRecord[] | null
  loading: boolean
  user: AuthUser
  branches: Branch[]
  section?: string
  onRefresh: () => void
}) {
  const [serviceDialogOpen, setServiceDialogOpen] = useState(false)
  const [imageService, setImageService] = useState<ServiceRecord | null>(null)
  const [editService, setEditService] = useState<ServiceRecord | null>(null)
  const canManage = user.permissions.includes('services.manage')
  const canManageSchedules = user.permissions.includes('schedules.manage')
  const view = section || 'catalogue'
  const servicesPage = usePaginatedList(data ?? [], 12)

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">TREATMENT MENU</span><h1>Services</h1><p className="muted">{view === 'schedules' ? 'Open hours and bookable resources for every branch.' : 'Review available treatments, branch pricing, and booking policies.'}</p></div>
        <div className="appointments-heading-actions">
          {view === 'catalogue' && canManage && <button className="button button-primary" type="button" onClick={() => setServiceDialogOpen(true)}>＋ Add service</button>}
        </div>
      </div>
      {view === 'schedules' && canManageSchedules ? (
        <ScheduleManagementDialog user={user} branches={branches} inline />
      ) : view === 'schedules' ? (
        <section className="panel"><EmptyState title="Schedule access required" message="Ask an administrator for the schedules.manage permission to configure branch hours and bookable resources." /></section>
      ) : loading && !data ? <section className="panel"><LoadingState compact /></section> : servicesPage.total ? (
        <>
          <div className="service-grid">{servicesPage.pageItems.map((service) => (
            <article className="service-card" key={service.id}>
              {service.primary_image_url && <img className="service-card-image" src={service.primary_image_url} alt="" loading="lazy" />}
              <div className="service-card-heading"><span className="table-icon sage">✳</span><span><h2>{service.name}</h2><small>{service.category?.name ?? service.classification}{service.is_active === false ? ' · Archived' : ''}</small></span></div>
              <p>{service.description || 'No service description provided.'}</p>
              <div className="service-meta"><span>{service.duration_minutes} min</span>{service.buffer_minutes > 0 && <span>{service.buffer_minutes} min buffer</span>}{service.images?.length ? <span>{service.images.length} image{service.images.length === 1 ? '' : 's'}</span> : null}</div>
              <div className="service-branches">{service.branches.map((branch) => <span className="role-chip" key={branch.id}>{branch.name} · {branch.currency_code} {Number(branch.price).toFixed(2)}{branch.is_available === false ? ' · unavailable' : ''}</span>)}</div>
              {canManage && <div className="service-card-actions"><button className="button button-secondary button-wide" type="button" onClick={() => setEditService(service)}>Edit details</button><button className="button button-secondary button-wide" type="button" onClick={() => setImageService(service)}>Manage images ({service.images?.length ?? 0})</button></div>}
            </article>
          ))}</div>
          <Pagination page={servicesPage.currentPage} totalPages={servicesPage.totalPages} total={servicesPage.total} pageSize={servicesPage.pageSize} onPage={servicesPage.setCurrentPage} label="Services pagination" />
        </>
      ) : <section className="panel"><EmptyState title="No services configured" message="Add treatments to your catalogue and make them available at the appropriate branches." /></section>}
      {imageService && <CatalogueImageDialog
        title={`${imageService.name} images`}
        images={imageService.images ?? []}
        uploadEndpoint={`/services/${imageService.id}/images`}
        imageEndpoint={`/services/${imageService.id}/images`}
        onClose={() => setImageService(null)}
        onChanged={onRefresh}
      />}
      {serviceDialogOpen && <ServiceSetupDialog branches={branches} onClose={() => setServiceDialogOpen(false)} onSaved={() => { setServiceDialogOpen(false); onRefresh() }} />}
      {editService && <ServiceSetupDialog branches={branches} service={editService} onClose={() => setEditService(null)} onSaved={() => { setEditService(null); onRefresh() }} />}
    </div>
  )
}

function CatalogueImageDialog({
  title,
  images,
  uploadEndpoint,
  imageEndpoint,
  onClose,
  onChanged,
}: {
  title: string
  images: CatalogueImage[]
  uploadEndpoint: string
  imageEndpoint: string
  onClose: () => void
  onChanged: () => void
}) {
  const [current, setCurrent] = useState<CatalogueImage[]>(images)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    if (!form.getAll('images[]').length) {
      setNotice('Choose at least one image to upload.')
      return
    }
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ images: CatalogueImage[] }>(uploadEndpoint, { method: 'POST', body: form })
      setCurrent(result.images)
      formElement.reset()
      setNotice('Images uploaded.')
      onChanged()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function removeImage(id: number) {
    if (!window.confirm('Remove this image?')) return
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ images: CatalogueImage[] }>(`${imageEndpoint}/${id}`, { method: 'DELETE' })
      setCurrent(result.images)
      setNotice('Image removed.')
      onChanged()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function makePrimary(id: number) {
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ images: CatalogueImage[] }>(`${imageEndpoint}/${id}/primary`, { method: 'PATCH' })
      setCurrent(result.images)
      setNotice('Primary image updated.')
      onChanged()
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="catalogue-image-title">
        <div className="dialog-heading"><div><span className="eyebrow">CATALOGUE MEDIA</span><h2 id="catalogue-image-title">{title}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {notice && <div className="notice info" role="status">{notice}</div>}
        {current.length ? <div className="catalogue-image-grid">{current.map((image) => (
          <figure className={`catalogue-image-tile ${image.is_primary ? 'is-primary' : ''}`} key={image.id}>
            <img src={image.url} alt="" loading="lazy" />
            {image.is_primary && <figcaption>Primary</figcaption>}
            <div className="catalogue-image-actions">
              {!image.is_primary && <button className="text-button" type="button" disabled={busy} onClick={() => void makePrimary(image.id)}>Make primary</button>}
              <button className="text-button" type="button" disabled={busy} onClick={() => void removeImage(image.id)}>Remove</button>
            </div>
          </figure>
        ))}</div> : <EmptyState compact title="No images yet" message="Upload up to 12 images; the first one becomes the primary image." />}
        <form className="customer-form" onSubmit={(event) => void upload(event)}>
          <label>Add images (JPG, PNG or WebP, max 5 MB each)<input name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Close</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Uploading…' : 'Upload images'}</button></div>
        </form>
      </section>
    </div>
  )
}

function ServiceSetupDialog({
  branches,
  service: existingService,
  onClose,
  onSaved,
}: {
  branches: Branch[]
  service?: ServiceRecord
  onClose: () => void
  onSaved: () => void
}) {
  const editing = Boolean(existingService)
  const initialBranch = existingService?.branches[0]
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [categories, setCategories] = useState<{ id: number; name: string }[]>([])
  const [branchId, setBranchId] = useState<number>(initialBranch?.id ?? branches[0]?.id ?? 0)
  const [pricing, setPricing] = useState({
    price: initialBranch?.price ?? '0.00',
    currency_code: initialBranch?.currency_code ?? 'KES',
    deposit_policy: initialBranch?.deposit_policy ?? 'pay_at_clinic',
    deposit_amount: initialBranch?.deposit_amount ?? '0.00',
    is_public: initialBranch?.is_public ?? false,
    is_available: initialBranch?.is_available ?? true,
  })

  useEffect(() => {
    let active = true
    apiRequest<{ id: number; name: string }[]>('/service-categories')
      .then((rows) => { if (active) setCategories(rows) })
      .catch(() => { if (active) setCategories([]) })
    return () => { active = false }
  }, [])

  function changeBranch(nextId: number) {
    setBranchId(nextId)
    const match = existingService?.branches.find((branch) => branch.id === nextId)
    setPricing({
      price: match?.price ?? '0.00',
      currency_code: match?.currency_code ?? 'KES',
      deposit_policy: match?.deposit_policy ?? 'pay_at_clinic',
      deposit_amount: match?.deposit_amount ?? '0.00',
      is_public: match?.is_public ?? false,
      is_available: match?.is_available ?? true,
    })
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    let serviceSaved = false
    try {
      const payload = {
        name: String(form.get('name')),
        description: String(form.get('description') ?? '') || null,
        classification: String(form.get('classification')),
        duration_minutes: Number(form.get('duration_minutes')),
        buffer_minutes: Number(form.get('buffer_minutes') || 0),
        category_id: form.get('category_id') ? Number(form.get('category_id')) : null,
        is_active: editing ? form.get('is_active') === 'on' : true,
      }
      const service = existingService
        ? await apiRequest<ServiceRecord>(`/services/${existingService.id}`, { method: 'PUT', body: JSON.stringify(payload) })
        : await apiRequest<ServiceRecord>('/services', { method: 'POST', body: JSON.stringify(payload) })
      serviceSaved = true
      await apiRequest(`/services/${service.id}/branches`, {
        method: 'PUT',
        body: JSON.stringify({
          branch_id: branchId,
          price: Number(pricing.price),
          currency_code: pricing.currency_code.toUpperCase(),
          is_available: pricing.is_available,
          is_public: pricing.is_public,
          deposit_policy: pricing.deposit_policy,
          deposit_amount: Number(pricing.deposit_amount || 0),
        }),
      })
      onSaved()
    } catch (requestError) {
      const message = getErrorMessage(requestError)
      setError(serviceSaved
        ? `The service was saved, but branch pricing could not be saved. ${message}`
        : message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="service-title">
        <div className="dialog-heading"><div><span className="eyebrow">SERVICE CATALOGUE</span><h2 id="service-title">{editing ? 'Edit service' : 'Add a service'}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {!branches.length ? <EmptyState title="No branch available" message="Create or assign a branch before configuring service pricing." /> : (
          <form className="customer-form form-stack" onSubmit={(event) => void submit(event)}>
            <section className="form-section">
              <div className="form-section-heading"><strong>Service details</strong><small>Name, description and how the treatment is classified.</small></div>
              <label>Service name<input name="name" required maxLength={160} defaultValue={existingService?.name ?? ''} /></label>
              <label>Description<textarea name="description" rows={3} maxLength={5000} defaultValue={existingService?.description ?? ''} /></label>
              <div className="form-section-grid"><label>Classification<select name="classification" defaultValue={existingService?.classification ?? 'clinical'}><option value="clinical">Clinical</option><option value="beauty">Beauty</option><option value="aesthetic">Aesthetic</option><option value="spa">Spa</option><option value="other">Other</option></select></label><label>Category<select name="category_id" defaultValue={existingService?.category?.id ?? ''}><option value="">Uncategorised</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label></div>
              <div className="form-section-grid"><label>Duration (minutes)<input name="duration_minutes" type="number" min={5} max={600} required defaultValue={existingService?.duration_minutes ?? 30} /></label><label>Buffer (minutes)<input name="buffer_minutes" type="number" min={0} max={180} defaultValue={existingService?.buffer_minutes ?? 0} /></label></div>
            </section>
            <section className="form-section">
              <div className="form-section-heading"><strong>Branch pricing</strong><small>Applies to the selected branch. Switch branches to price another location.</small></div>
              <div className="form-section-grid"><label>Branch<select name="branch_id" required value={branchId} onChange={(event) => changeBranch(Number(event.target.value))}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label><label>Price<input name="price" type="number" min={0} step="0.01" required value={pricing.price} onChange={(event) => setPricing({ ...pricing, price: event.target.value })} /></label></div>
              <div className="form-section-grid"><label>Currency<input name="currency_code" minLength={3} maxLength={3} required value={pricing.currency_code} onChange={(event) => setPricing({ ...pricing, currency_code: event.target.value })} /></label><label>Deposit policy<select name="deposit_policy" value={pricing.deposit_policy} onChange={(event) => setPricing({ ...pricing, deposit_policy: event.target.value })}><option value="pay_at_clinic">Pay at clinic</option><option value="fixed">Fixed deposit</option><option value="full">Full payment</option></select></label></div>
              <label>Deposit amount<input name="deposit_amount" type="number" min={0} step="0.01" value={pricing.deposit_amount} onChange={(event) => setPricing({ ...pricing, deposit_amount: event.target.value })} /></label>
            </section>
            <section className="form-section">
              <div className="form-section-heading"><strong>Visibility</strong><small>Control where this service appears and whether it accepts bookings.</small></div>
              <label className="customer-check"><input name="is_public" type="checkbox" checked={pricing.is_public} onChange={(event) => setPricing({ ...pricing, is_public: event.target.checked })} /> Show in public booking catalogue</label>
              {editing && <label className="customer-check"><input type="checkbox" checked={pricing.is_available} onChange={(event) => setPricing({ ...pricing, is_available: event.target.checked })} /> Available at this branch</label>}
              {editing && <label className="customer-check"><input name="is_active" type="checkbox" defaultChecked={existingService?.is_active !== false} /> Active in the service catalogue</label>}
            </section>
            <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save service'}</button></div>
          </form>
        )}
      </section>
    </div>
  )
}

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

function ScheduleManagementDialog({
  user,
  branches,
  inline = false,
  onClose,
}: {
  user: AuthUser
  branches: Branch[]
  inline?: boolean
  onClose?: () => void
}) {
  const closedHours = () => WEEKDAYS.map((_, day_of_week) => ({
    day_of_week,
    is_closed: true,
    opens_at: null,
    closes_at: null,
  }))
  const [branchId, setBranchId] = useState(String(branches[0]?.id ?? user.branch_id ?? ''))
  const [hours, setHours] = useState<BranchBusinessHour[]>(closedHours())
  const [resources, setResources] = useState<BookingResourceRecord[]>([])
  const [staffMembers, setStaffMembers] = useState<{ id: number; name: string }[]>([{ id: user.id, name: user.name }])
  const [resourceType, setResourceType] = useState<BookingResourceRecord['resource_type']>('room')
  const [resourceDraft, setResourceDraft] = useState<ResourceScheduleDraft | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const canViewUsers = user.permissions.includes('users.view')

  useEffect(() => {
    if (!branchId) return
    let active = true
    const userRequest = canViewUsers
      ? apiRequest<Paginated<ApiUser>>('/users').then((result) => result.data.map((member) => ({ id: member.id, name: member.name })))
      : Promise.resolve([{ id: user.id, name: user.name }])
    void Promise.all([
      apiRequest<BranchBusinessHour[]>(`/branch-business-hours?branch_id=${encodeURIComponent(branchId)}`),
      apiRequest<BookingResourceRecord[]>('/booking-resources'),
      userRequest,
    ]).then(([branchHours, branchResources, availableStaff]) => {
      if (!active) return
      setHours(WEEKDAYS.map((_, day_of_week) => branchHours.find((entry) => entry.day_of_week === day_of_week) ?? closedHours()[day_of_week]))
      setResources(branchResources.filter((resource) => String(resource.branch_id) === branchId))
      setStaffMembers(availableStaff)
    }).catch((requestError: unknown) => {
      if (active) setError(getErrorMessage(requestError))
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [branchId, canViewUsers, user.id, user.name])

  function updateHours(day: number, patch: Partial<BranchBusinessHour>) {
    setHours((current) => current.map((entry) => entry.day_of_week === day ? { ...entry, ...patch } : entry))
  }

  async function saveHours(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const saved = await apiRequest<BranchBusinessHour[]>('/branch-business-hours', {
        method: 'PUT',
        body: JSON.stringify({ branch_id: Number(branchId), hours }),
      })
      setHours(WEEKDAYS.map((_, day_of_week) => saved.find((entry) => entry.day_of_week === day_of_week) ?? hours[day_of_week]))
      setNotice('Branch opening hours have been saved.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function addResource(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    const form = new FormData(event.currentTarget)
    try {
      const resource = await apiRequest<BookingResourceRecord>('/booking-resources', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: Number(branchId),
          name: String(form.get('name')),
          resource_type: resourceType,
          ...(resourceType === 'staff' ? { user_id: Number(form.get('user_id')) } : {}),
        }),
      })
      setResources((current) => [...current, { ...resource, schedules: [] }])
      setNotice(`${formatAction(resource.resource_type)} resource added. Configure its weekly schedule before booking.`)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveResourceSchedule(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!resourceDraft) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const saved = await apiRequest<BookingResourceRecord>(`/booking-resources/${resourceDraft.resourceId}/schedule`, {
        method: 'PUT',
        body: JSON.stringify({ schedules: resourceDraft.schedules }),
      })
      setResources((current) => current.map((resource) => resource.id === saved.id ? saved : resource))
      setResourceDraft(null)
      setNotice(`${saved.name} schedule has been saved.`)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  if (!branches.length) {
    return <div className="dialog-backdrop"><section className="dialog customer-dialog" role="dialog" aria-modal="true"><div className="dialog-heading"><h2>Scheduling setup</h2>{!inline && <button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button>}</div><EmptyState title="No branch available" message="Assign a branch before configuring schedules." /></section></div>
  }

  return (
    <div className={`dialog-backdrop ${inline ? 'dialog-backdrop-inline' : ''}`} role="presentation" onMouseDown={(event) => { if (!inline && event.target === event.currentTarget) onClose?.() }}>
      <section className={`dialog customer-dialog schedule-dialog ${inline ? 'schedule-dialog-inline' : ''}`} role={inline ? 'region' : 'dialog'} aria-modal={inline ? undefined : true} aria-labelledby="schedule-title">
        <div className="dialog-heading"><div><span className="eyebrow">BOOKING SETUP</span><h2 id="schedule-title">Branch schedules</h2></div>{!inline && <button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button>}</div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {notice && <div className="notice success" role="status">{notice}</div>}
        <label className="schedule-branch">Branch<select value={branchId} onChange={(event) => { setBranchId(event.target.value); setLoading(true); setError(''); setNotice(''); setResourceDraft(null) }}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
        {loading ? <LoadingState compact /> : (
          <>
            <form className="schedule-section" onSubmit={(event) => void saveHours(event)}>
              <div className="schedule-section-heading"><div><h3>Business hours</h3><p>Appointments are only offered during open hours.</p></div><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save hours'}</button></div>
              <div className="weekly-schedule">{hours.map((entry) => (
                <div className="weekly-schedule-row" key={entry.day_of_week}>
                  <strong>{WEEKDAYS[entry.day_of_week]}</strong>
                  <label className="customer-check"><input type="checkbox" checked={!entry.is_closed} onChange={(event) => updateHours(entry.day_of_week, { is_closed: !event.target.checked, opens_at: event.target.checked ? entry.opens_at ?? '09:00' : null, closes_at: event.target.checked ? entry.closes_at ?? '17:00' : null })} /> Open</label>
                  {!entry.is_closed && <><input aria-label={`${WEEKDAYS[entry.day_of_week]} opening time`} type="time" value={entry.opens_at?.slice(0, 5) ?? ''} onChange={(event) => updateHours(entry.day_of_week, { opens_at: event.target.value })} /><input aria-label={`${WEEKDAYS[entry.day_of_week]} closing time`} type="time" value={entry.closes_at?.slice(0, 5) ?? ''} onChange={(event) => updateHours(entry.day_of_week, { closes_at: event.target.value })} /></>}
                  {entry.is_closed && <span className="muted schedule-closed">Closed</span>}
                </div>
              ))}</div>
            </form>

            <section className="schedule-section">
              <div className="schedule-section-heading"><div><h3>Staff, rooms & equipment</h3><p>Every resource needs a weekly schedule to be bookable.</p></div></div>
              {resources.length ? <div className="schedule-resource-list">{resources.map((resource) => (
                <div className="schedule-resource-row" key={resource.id}>
                  <span><strong>{resource.name}</strong><small>{formatAction(resource.resource_type)} · {resource.schedules.length}/7 days configured</small></span>
                  <button className="button button-secondary" type="button" onClick={() => setResourceDraft({
                    resourceId: resource.id,
                    schedules: WEEKDAYS.map((_, day_of_week) => {
                      const saved = resource.schedules.find((schedule) => schedule.day_of_week === day_of_week)
                      return { day_of_week, is_unavailable: saved?.is_unavailable ?? true, starts_at: saved?.starts_at ?? null, ends_at: saved?.ends_at ?? null }
                    }),
                  })}>Set hours</button>
                </div>
              ))}</div> : <p className="muted">No booking resources are configured for this branch.</p>}
              <form className="resource-create-form" onSubmit={(event) => void addResource(event)}>
                <label>Resource name<input name="name" required maxLength={160} /></label>
                <label>Type<select value={resourceType} onChange={(event) => setResourceType(event.target.value as BookingResourceRecord['resource_type'])}><option value="room">Room</option><option value="equipment">Equipment</option><option value="staff">Staff</option></select></label>
                {resourceType === 'staff' && <label>Team member<select name="user_id" required>{staffMembers.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select></label>}
                <button className="button button-secondary" type="submit" disabled={busy}>{busy ? 'Adding…' : 'Add resource'}</button>
              </form>
            </section>
            {resourceDraft && (
              <form className="schedule-editor" onSubmit={(event) => void saveResourceSchedule(event)}>
                <div className="schedule-section-heading"><div><h3>Resource weekly availability</h3><p>Unavailable days are excluded from appointment slots.</p></div><button className="button-icon-only" type="button" aria-label="Close resource schedule" onClick={() => setResourceDraft(null)}>×</button></div>
                <div className="weekly-schedule">{resourceDraft.schedules.map((entry) => (
                  <div className="weekly-schedule-row" key={entry.day_of_week}>
                    <strong>{WEEKDAYS[entry.day_of_week]}</strong>
                    <label className="customer-check"><input type="checkbox" checked={!entry.is_unavailable} onChange={(event) => setResourceDraft({ ...resourceDraft, schedules: resourceDraft.schedules.map((schedule) => schedule.day_of_week === entry.day_of_week ? { ...schedule, is_unavailable: !event.target.checked, starts_at: event.target.checked ? schedule.starts_at ?? '09:00' : null, ends_at: event.target.checked ? schedule.ends_at ?? '17:00' : null } : schedule) })} /> Available</label>
                    {!entry.is_unavailable && <><input aria-label={`${WEEKDAYS[entry.day_of_week]} resource start`} type="time" value={entry.starts_at?.slice(0, 5) ?? ''} onChange={(event) => setResourceDraft({ ...resourceDraft, schedules: resourceDraft.schedules.map((schedule) => schedule.day_of_week === entry.day_of_week ? { ...schedule, starts_at: event.target.value } : schedule) })} /><input aria-label={`${WEEKDAYS[entry.day_of_week]} resource end`} type="time" value={entry.ends_at?.slice(0, 5) ?? ''} onChange={(event) => setResourceDraft({ ...resourceDraft, schedules: resourceDraft.schedules.map((schedule) => schedule.day_of_week === entry.day_of_week ? { ...schedule, ends_at: event.target.value } : schedule) })} /></>}
                    {entry.is_unavailable && <span className="muted schedule-closed">Unavailable</span>}
                  </div>
                ))}</div>
                <button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save resource hours'}</button>
              </form>
            )}
          </>
        )}
      </section>
    </div>
  )
}

function CustomersPage({
  data,
  loading,
  user,
  branches,
  page,
  onPage,
  onRefresh,
  onNavigate,
}: {
  data: Paginated<CustomerRecord> | null
  loading: boolean
  user: AuthUser
  branches: Branch[]
  page: number
  onPage: (page: number) => void
  onRefresh: () => void
  onNavigate: (page: PageKey) => void
}) {
  const [search, setSearch] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [registerOpen, setRegisterOpen] = useState(false)
  const [searchResults, setSearchResults] = useState<CustomerRecord[] | null>(null)
  const [searchError, setSearchError] = useState('')
  const canRegister = user.permissions.includes('customers.create')

  useEffect(() => {
    if (!search.trim()) return

    let active = true
    const timer = window.setTimeout(() => {
      setSearchError('')
      void apiRequest<Paginated<CustomerRecord>>(`/customers?search=${encodeURIComponent(search.trim())}`)
        .then((result) => {
          if (active) setSearchResults(result.data)
        })
        .catch((requestError: unknown) => {
          if (active) {
            setSearchResults([])
            setSearchError(requestError instanceof Error ? requestError.message : 'Customer search failed.')
          }
        })
    }, 250)
    return () => {
      active = false
      window.clearTimeout(timer)
    }
  }, [search])

  if (selectedId !== null) {
    return (
      <CustomerDetails
        customerId={selectedId}
        user={user}
        branches={branches}
        onBack={() => setSelectedId(null)}
        onRefresh={onRefresh}
        onSelectCustomer={setSelectedId}
        onNavigate={onNavigate}
      />
    )
  }

  const customers = search.trim() ? searchResults ?? [] : data?.data ?? []
  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">CUSTOMER IDENTITY</span>
          <h1>Customers</h1>
          <p className="muted">Search customer records and manage patient-linked profiles.</p>
        </div>
        {canRegister && <button className="button button-primary" type="button" onClick={() => setRegisterOpen(true)}>＋ Register customer</button>}
      </div>
      <section className="panel customer-directory">
        <label className="customer-search">
          <span aria-hidden="true">⌕</span>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email or phone" />
        </label>
        {searchError && <div className="notice error" role="alert">{searchError}</div>}
        {loading && !data ? <LoadingState /> : customers.length ? (
          <div className="table-scroll"><table><thead><tr><th>CUSTOMER</th><th>CONTACT</th><th>REGISTERED AT</th><th>PROFILE</th><th>STATUS</th></tr></thead>
            <tbody>{customers.map((customer) => (
              <tr key={customer.id}>
                <td><button className="customer-row-link" type="button" onClick={() => setSelectedId(customer.id)}><span className="table-entity"><span className="profile-avatar small">{initials(customer.name)}</span><strong>{customer.name}</strong></span></button></td>
                <td>{customer.email ?? customer.phone ?? '—'}</td>
                <td>{customer.registered_branch?.name ?? '—'}</td>
                <td>{customer.patient_profile_linked === undefined ? <span className="muted">Restricted</span> : customer.patient_profile_linked ? <span className="role-chip">Patient</span> : <span className="muted">Customer</span>}</td>
                <td><StatusPill active={customer.status === 'active'} /></td>
              </tr>
            ))}</tbody></table>
          </div>
        ) : <EmptyState title={search ? 'No matching customers' : 'No customers yet'} message={search ? 'Try another name, email or phone number.' : 'Customer profiles will appear here after registration.'} />}
        {data?.data && !search && <Pagination page={data.current_page ?? page} totalPages={data.last_page ?? 1} total={data.total} pageSize={data.per_page ?? 25} onPage={onPage} label="Customers pagination" />}
      </section>
      {registerOpen && (
        <CustomerRegistration
          user={user}
          branches={branches}
          onClose={() => setRegisterOpen(false)}
          onCreated={(customer) => {
            setRegisterOpen(false)
            setSearch('')
            setSelectedId(customer.id)
            onRefresh()
          }}
        />
      )}
    </div>
  )
}

function CustomerRegistration({
  user,
  branches,
  onClose,
  onCreated,
}: {
  user: AuthUser
  branches: Branch[]
  onClose: () => void
  onCreated: (customer: Pick<CustomerRecord, 'id'>) => void
}) {
  const [error, setError] = useState('')
  const [duplicate, setDuplicate] = useState<{ id: number; name: string; email: string | null; phone: string | null } | null>(null)
  const [busy, setBusy] = useState(false)
  const canRegisterPatient = user.permissions.includes('patients.register')
  const canActivatePortal = user.permissions.includes('customer.portal.activate')
  const canChooseBranch = user.permissions.includes('branch.view_all')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setDuplicate(null)
    const form = new FormData(event.currentTarget)
    const patient = {
      date_of_birth: String(form.get('date_of_birth') ?? '') || null,
      emergency_contact_name: String(form.get('emergency_contact_name') ?? '') || null,
      emergency_contact_phone: String(form.get('emergency_contact_phone') ?? '') || null,
      emergency_contact_relationship: String(form.get('emergency_contact_relationship') ?? '') || null,
    }
    const payload = {
      name: String(form.get('name') ?? ''),
      email: String(form.get('email') ?? '') || null,
      phone: String(form.get('phone') ?? '') || null,
      preferred_channel: String(form.get('preferred_channel') ?? 'none'),
      registration_source: String(form.get('registration_source') ?? 'walk_in'),
      branch_id: String(form.get('branch_id') ?? '') || null,
      is_patient: canRegisterPatient && form.get('is_patient') === 'on',
      patient,
      activate_portal: canActivatePortal && form.get('activate_portal') === 'on',
    }
    try {
      const customer = await apiRequest<CustomerRecord>('/customers', { method: 'POST', body: JSON.stringify(payload) })
      onCreated(customer)
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 409) {
        const body = requestError.responseBody as { duplicate_customer?: typeof duplicate } | null
        setDuplicate(body?.duplicate_customer ?? null)
      }
      setError(requestError instanceof Error ? requestError.message : 'Customer registration failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="customer-register-title">
        <div className="dialog-heading"><div><span className="eyebrow">WALK-IN REGISTRATION</span><h2 id="customer-register-title">Register customer</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {duplicate && <div className="notice customer-duplicate" role="status"><strong>Possible existing profile: {duplicate.name}</strong><span>{duplicate.email ?? duplicate.phone ?? ''}</span><button className="text-button" type="button" onClick={() => onCreated(duplicate)}>Review existing profile</button></div>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Full name<input name="name" required maxLength={255} autoComplete="name" /></label>
          <div className="customer-form-grid">
            <label>Email<input name="email" type="email" maxLength={255} autoComplete="email" /></label>
            <label>Phone<input name="phone" type="tel" maxLength={40} autoComplete="tel" /></label>
          </div>
          <div className="customer-form-grid">
            <label>Preferred contact<select name="preferred_channel" defaultValue="none"><option value="none">No preference</option><option value="email">Email</option><option value="sms">SMS</option><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option></select></label>
            <label>Registration source<select name="registration_source" defaultValue="walk_in"><option value="walk_in">Walk-in</option><option value="referral">Referral</option><option value="website">Website</option><option value="phone">Phone</option><option value="other">Other</option></select></label>
          </div>
          {canChooseBranch && <label>Registration branch<select name="branch_id" defaultValue={user.branch_id ?? ''}><option value="">No branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
          {canRegisterPatient && <label className="customer-check"><input name="is_patient" type="checkbox" /> Create a linked patient profile</label>}
          {canRegisterPatient && (
            <fieldset className="customer-patient-fields">
              <legend>Patient details (optional)</legend>
              <label>Date of birth<input name="date_of_birth" type="date" /></label>
              <div className="customer-form-grid"><label>Emergency contact<input name="emergency_contact_name" maxLength={255} /></label><label>Contact phone<input name="emergency_contact_phone" type="tel" maxLength={40} /></label></div>
              <label>Relationship<input name="emergency_contact_relationship" maxLength={60} /></label>
            </fieldset>
          )}
          {canActivatePortal && <label className="customer-check"><input name="activate_portal" type="checkbox" /> Activate a customer portal account (requires email)</label>}
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Registering…' : 'Register customer'}</button></div>
        </form>
      </section>
    </div>
  )
}

function CustomerDetails({
  customerId,
  user,
  branches,
  onBack,
  onRefresh,
  onSelectCustomer,
  onNavigate,
}: {
  customerId: number
  user: AuthUser
  branches: Branch[]
  onBack: () => void
  onRefresh: () => void
  onSelectCustomer: (customerId: number) => void
  onNavigate: (page: PageKey) => void
}) {
  const [customer, setCustomer] = useState<CustomerRecord | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [consentOpen, setConsentOpen] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [patientOpen, setPatientOpen] = useState(false)
  const [mergeOpen, setMergeOpen] = useState(false)
  const [portalBusy, setPortalBusy] = useState(false)
  const [activityState, setActivityState] = useState<CustomerActivityRecord | null>(null)
  const [activityErrorState, setActivityErrorState] = useState<{ customerId: number; message: string } | null>(null)
  const [activityTab, setActivityTab] = useState<CustomerActivityTab>('orders')
  const activity = activityState?.customer_id === customerId ? activityState : null
  const activityError = activityErrorState?.customerId === customerId ? activityErrorState.message : ''
  const activityLoading = !activity && !activityError
  const canManageConsents = user.permissions.includes('consents.manage')
  const canRecordHistory = user.permissions.includes('customers.history.record')
  const canManagePatients = user.permissions.includes('patients.manage')
  const canUpdateCustomer = user.permissions.includes('customers.update')
  const canActivatePortal = user.permissions.includes('customer.portal.activate')
  const canMergeCustomers = user.permissions.includes('customers.merge')
  const [bookingOpen, setBookingOpen] = useState(false)
  const [cancelTarget, setCancelTarget] = useState<CustomerActivityAppointment | null>(null)
  const [depositTarget, setDepositTarget] = useState<CustomerActivityAppointment | null>(null)
  const [rescheduleTarget, setRescheduleTarget] = useState<CustomerActivityAppointment | null>(null)
  const [refundTarget, setRefundTarget] = useState<CustomerActivitySale | null>(null)
  const [clinicalDialog, setClinicalDialog] = useState<{ mode: 'create' } | { mode: 'amend'; encounter: CustomerActivityEncounter } | null>(null)
  const [completeTarget, setCompleteTarget] = useState<CustomerActivityEncounter | null>(null)
  const [loyaltyOpen, setLoyaltyOpen] = useState(false)
  const [settlementTarget, setSettlementTarget] = useState<CustomerActivityPayment | null>(null)
  const [activityBusy, setActivityBusy] = useState<string | null>(null)

  const refreshCustomer = useCallback(async () => {
    try {
      setCustomer(await apiRequest<CustomerRecord>(`/customers/${customerId}`))
      setError('')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Could not load customer details.')
    } finally {
      setLoading(false)
    }
  }, [customerId])

  const refreshActivity = useCallback(async () => {
    try {
      setActivityState(await apiRequest<CustomerActivityRecord>(`/customers/${customerId}/activity`))
      setActivityErrorState(null)
    } catch (requestError) {
      setActivityErrorState({
        customerId,
        message: requestError instanceof Error ? requestError.message : 'Could not load customer activity.',
      })
    }
  }, [customerId])

  useEffect(() => {
    let active = true
    void apiRequest<CustomerRecord>(`/customers/${customerId}`)
      .then((result) => {
        if (active) {
          setCustomer(result)
          setError('')
        }
      })
      .catch((requestError: unknown) => {
        if (active) setError(requestError instanceof Error ? requestError.message : 'Could not load customer details.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [customerId])

  useEffect(() => {
    let active = true
    void apiRequest<CustomerActivityRecord>(`/customers/${customerId}/activity`)
      .then((result) => {
        if (active) {
          setActivityState(result)
          setActivityErrorState(null)
        }
      })
      .catch((requestError: unknown) => {
        if (active) {
          setActivityErrorState({
            customerId,
            message: requestError instanceof Error ? requestError.message : 'Could not load customer activity.',
          })
        }
      })
    return () => { active = false }
  }, [customerId])

  async function recordVisit(branchId: number) {
    setNotice('')
    try {
      await apiRequest(`/customers/${customerId}/branch-history`, {
        method: 'POST',
        body: JSON.stringify({ branch_id: branchId }),
      })
      setNotice('The branch visit was recorded.')
      await refreshCustomer()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Could not record the visit.')
    }
  }

  async function sendPortalActivation() {
    setPortalBusy(true)
    setNotice('')
    setError('')
    try {
      await apiRequest(`/customers/${customerId}/portal-activation`, { method: 'POST' })
      setNotice('Portal activation instructions were sent to the customer email.')
      await refreshCustomer()
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setPortalBusy(false)
    }
  }

  async function runActivityRequest(key: string, request: () => Promise<unknown>, message: string) {
    setActivityBusy(key)
    setError('')
    setNotice('')
    try {
      await request()
      setNotice(message)
      await refreshActivity()
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setActivityBusy(null)
    }
  }

  async function transitionAppointment(appointmentId: number, action: 'check_in' | 'start' | 'complete' | 'no_show') {
    await runActivityRequest(
      `appointment-${appointmentId}-${action}`,
      () => apiRequest(`/appointments/${appointmentId}/status`, { method: 'PATCH', body: JSON.stringify({ action }) }),
      `Appointment ${formatAction(action).toLowerCase()} recorded.`,
    )
  }

  async function setOrderStatus(orderId: number, status: string) {
    if (status === 'cancelled' && !window.confirm('Cancel this order? Any promotion reservation is released.')) return
    await runActivityRequest(
      `order-${orderId}`,
      () => apiRequest(`/ecommerce/orders/${orderId}/fulfillment`, { method: 'PATCH', body: JSON.stringify({ status }) }),
      `Order marked ${formatAction(status).toLowerCase()}.`,
    )
  }

  async function decideOrderReturn(orderId: number, decision: 'approve' | 'reject') {
    if (!window.confirm(`${decision === 'approve' ? 'Approve' : 'Reject'} this return request?`)) return
    await runActivityRequest(
      `return-${orderId}`,
      () => apiRequest(`/ecommerce/orders/${orderId}/return-decision`, { method: 'POST', body: JSON.stringify({ decision }) }),
      decision === 'approve' ? 'Return approved. The provider refund must be completed separately.' : 'Return request rejected.',
    )
  }

  function finishActivityAction(message: string) {
    setNotice(message)
    void refreshActivity()
    onRefresh()
  }

  const activityTabs: { key: CustomerActivityTab; label: string }[] = activity
    ? [
        ...(activity.capabilities.orders ? [{ key: 'orders' as const, label: `Online orders (${activity.orders.length})` }] : []),
        ...(activity.capabilities.pos_sales ? [{ key: 'pos' as const, label: `POS purchases (${activity.pos_sales.length})` }] : []),
        ...(activity.capabilities.payments ? [{ key: 'payments' as const, label: `Payments (${activity.payments.length})` }] : []),
        ...(activity.capabilities.appointments ? [{ key: 'appointments' as const, label: `Appointments (${activity.appointments.length})` }] : []),
        ...(activity.capabilities.clinical ? [{ key: 'clinical' as const, label: `Treatment plans (${activity.clinical_encounters.length})` }] : []),
        ...(activity.capabilities.loyalty ? [{ key: 'loyalty' as const, label: 'Rewards' }] : []),
      ]
    : []
  const activeActivityTab = activityTabs.some((tab) => tab.key === activityTab) ? activityTab : activityTabs[0]?.key

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div>
          <button className="text-button" type="button" onClick={onBack}>← Customers</button>
          <span className="eyebrow">CUSTOMER PROFILE</span>
          <h1>{customer?.name ?? 'Customer details'}</h1>
          <p className="muted">Manage identity, contact preferences, portal access, patient details, branch history and consent records.</p>
          {customer && (
            <div className="customer-identity-meta">
              <StatusPill active={customer.status === 'active'} />
              <span className="role-chip">{customer.patient_profile_linked === undefined ? 'Patient details restricted' : customer.patient_profile_linked ? 'Patient profile' : 'Customer only'}</span>
              {customer.portal_active && <span className="role-chip">Portal active</span>}
              {customer.portal_linked && !customer.portal_active && <span className="role-chip">Portal suspended</span>}
            </div>
          )}
        </div>
        <div className="customer-detail-actions">
          {canRecordHistory && branches.length > 0 && <label className="customer-visit-select"><span className="sr-only">Record visit at branch</span><select defaultValue="" onChange={(event) => { if (event.target.value) void recordVisit(Number(event.target.value)); event.target.value = '' }}><option value="">＋ Record visit</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
          {canManageConsents && <button className="button button-secondary" type="button" onClick={() => setConsentOpen(true)}>＋ Record consent</button>}
          {canManagePatients && customer && <button className="button button-secondary" type="button" onClick={() => setPatientOpen(true)}>{customer.patient_profile ? 'Edit patient profile' : '＋ Create patient profile'}</button>}
          {canActivatePortal && customer && !customer.portal_linked && customer.email && <button className="button button-secondary" type="button" disabled={portalBusy} onClick={() => void sendPortalActivation()}>{portalBusy ? 'Sending…' : 'Activate portal'}</button>}
          {canUpdateCustomer && customer && <button className="button button-primary" type="button" onClick={() => setEditOpen(true)}>Edit customer</button>}
          {canMergeCustomers && customer && <button className="button button-secondary danger-text" type="button" onClick={() => setMergeOpen(true)}>Merge profile</button>}
        </div>
      </div>
      {error && <div className="notice error" role="alert">{error}<button className="text-button" type="button" onClick={() => void refreshCustomer()}>Try again</button></div>}
      {notice && <div className="notice info" role="status">{notice}</div>}
      {loading ? <LoadingState /> : customer && (
        <div className="customer-detail-grid">
          <section className="panel customer-info-panel">
            <div className="section-heading"><h2>Contact details</h2><StatusPill active={customer.status === 'active'} /></div>
            <dl className="customer-facts">
              <div><dt>Email</dt><dd>{customer.email ?? '—'}</dd></div>
              <div><dt>Phone</dt><dd>{customer.phone ?? '—'}</dd></div>
              <div><dt>Preferred contact</dt><dd>{customer.preferred_channel}</dd></div>
              <div><dt>Registered at</dt><dd>{customer.registered_branch?.name ?? '—'}</dd></div>
              <div><dt>Registration</dt><dd>{formatAction(customer.registration_source)}</dd></div>
              {customer.created_at && <div><dt>Customer since</dt><dd>{formatDate(customer.created_at)}</dd></div>}
              <div><dt>Portal access</dt><dd className="customer-portal-fact">
                {customer.portal_active ? 'Active' : customer.portal_linked ? 'Suspended — reactivate the customer to restore access' : customer.email ? 'Not activated' : 'Add an email address to invite the customer'}
                {canActivatePortal && !customer.portal_linked && customer.email && <button className="text-button customer-inline-action" type="button" disabled={portalBusy} onClick={() => void sendPortalActivation()}>{portalBusy ? 'Sending…' : 'Send invite'}</button>}
              </dd></div>
            </dl>
          </section>
          <section className="panel customer-info-panel">
            <div className="section-heading">
              <h2>Patient profile</h2>
              <div className="customer-section-actions">
                {customer.patient_profile_linked && <span className="role-chip">Linked</span>}
                {canManagePatients && <button className="text-button" type="button" onClick={() => setPatientOpen(true)}>{customer.patient_profile ? 'Edit details' : 'Create profile'}</button>}
              </div>
            </div>
            {customer.patient_profile ? <dl className="customer-facts">
              <div><dt>Patient number</dt><dd>{customer.patient_profile.patient_number}</dd></div>
              <div><dt>Date of birth</dt><dd>{customer.patient_profile.date_of_birth ? formatDate(customer.patient_profile.date_of_birth) : '—'}</dd></div>
              <div><dt>Emergency contact</dt><dd>{customer.patient_profile.emergency_contact_name ?? '—'}</dd></div>
              <div><dt>Contact phone</dt><dd>{customer.patient_profile.emergency_contact_phone ?? '—'}</dd></div>
              <div><dt>Relationship</dt><dd>{customer.patient_profile.emergency_contact_relationship ?? '—'}</dd></div>
              <div><dt>Patient status</dt><dd><StatusPill active={customer.patient_profile.is_active} /></dd></div>
            </dl> : <p className="muted">{customer.patient_profile_linked === undefined ? 'Patient profile details are restricted for your role.' : canManagePatients ? 'No patient profile linked. Create one to record date of birth, emergency contact details, and clinical eligibility.' : 'No patient profile linked. A clinician or administrator can create one.'}</p>}
          </section>
          <section className="panel customer-info-panel">
            <div className="section-heading"><h2>Branch history</h2></div>
            {customer.branch_history.length ? <ul className="customer-history-list">{customer.branch_history.map((item) => <li key={item.id}><span className="history-dot" /><span><strong>{item.branch?.name ?? 'Branch'}</strong><small>{item.activity} · {formatDate(item.occurred_at)}</small></span></li>)}</ul> : <p className="muted">No branch activity recorded.</p>}
          </section>
          <section className="panel customer-info-panel">
            <div className="section-heading"><h2>Consents</h2></div>
            {customer.consents?.length ? <ul className="customer-consent-list">{customer.consents.map((consent) => <li key={consent.id}><span><strong>{consent.consent_type.replaceAll('_', ' ')}</strong><small>{consent.capture_method} · {formatDate(consent.captured_at)}</small></span><span className="customer-consent-actions">{consent.document_available && user.permissions.includes('consents.view') && <button className="text-button" type="button" onClick={() => { void apiDownload(`/customers/${customerId}/consents/${consent.id}/document`, `consent-${consent.id}`).catch((downloadError: unknown) => setError(downloadError instanceof Error ? downloadError.message : 'Could not download the consent document.')) }}>Download document</button>}<span className={`status-pill ${consent.status === 'granted' ? 'status-active' : 'status-inactive'}`}><i />{consent.status}</span></span></li>)}</ul> : <p className="muted">No consent records available to your role.</p>}
          </section>
          <section className="panel customer-activity-panel">
            <div className="section-heading">
              <div>
                <h2>Orders, payments &amp; care</h2>
                <p className="muted customer-activity-intro">Every online order, in-store purchase, payment, appointment and treatment plan linked to this customer.</p>
              </div>
              <span className="role-chip">Customer 360</span>
            </div>
            {activityLoading ? <LoadingState /> : activityError ? (
              <div className="notice error" role="alert">{activityError}<button className="text-button" type="button" onClick={() => void refreshActivity()}>Try again</button></div>
            ) : activity && (
              <>
                <div className="customer-activity-summary">
                  {activity.capabilities.orders && <div><small>Online orders</small><strong>{activity.summary.orders_total}</strong><span>{formatMoneyValue(activity.summary.orders_paid_total)} paid</span></div>}
                  {activity.capabilities.pos_sales && <div><small>POS purchases</small><strong>{activity.summary.pos_sales_total}</strong><span>{formatMoneyValue(activity.summary.pos_spend_total)} spent</span></div>}
                  {activity.capabilities.payments && <div><small>Payments</small><strong>{activity.summary.payments_total}</strong><span>{formatMoneyValue(activity.summary.payments_paid_total)} received</span></div>}
                  {activity.capabilities.appointments && <div><small>Upcoming appointments</small><strong>{activity.summary.appointments_upcoming}</strong><span>{activity.summary.appointments_total} total</span></div>}
                  {activity.capabilities.clinical && <div><small>Clinical encounters</small><strong>{activity.summary.clinical_encounters_total}</strong><span>{activity.summary.treatment_plans_total} treatment plans</span></div>}
                  {activity.capabilities.loyalty && <div><small>Reward points</small><strong>{activity.summary.loyalty_points}</strong><span>{activity.loyalty?.account?.tier ?? 'No loyalty account'}</span></div>}
                </div>
                {activityTabs.length > 0 ? (
                  <>
                    <div className="customer-activity-heading-row">
                      <div className="customer-activity-tabs" role="tablist" aria-label="Customer records">
                        {activityTabs.map((tab) => (
                          <button key={tab.key} className={`customer-activity-tab ${activeActivityTab === tab.key ? 'is-active' : ''}`} type="button" role="tab" aria-selected={activeActivityTab === tab.key} onClick={() => setActivityTab(tab.key)}>{tab.label}</button>
                        ))}
                      </div>
                      <div className="customer-activity-toolbar">
                        {activeActivityTab === 'appointments' && activity.capabilities.manage.book_appointments && <button className="button button-secondary" type="button" onClick={() => setBookingOpen(true)}>＋ Book appointment</button>}
                        {activeActivityTab === 'clinical' && activity.capabilities.manage.clinical && activity.patient_profile_linked && <button className="button button-secondary" type="button" onClick={() => setClinicalDialog({ mode: 'create' })}>＋ New encounter</button>}
                        {activeActivityTab === 'loyalty' && activity.capabilities.manage.loyalty && <button className="button button-secondary" type="button" onClick={() => setLoyaltyOpen(true)}>Adjust points</button>}
                        {activeActivityTab === 'orders' && <button className="text-button" type="button" onClick={() => onNavigate('ecommerce')}>Open orders module →</button>}
                        {activeActivityTab === 'pos' && <button className="text-button" type="button" onClick={() => onNavigate('pos')}>Open POS →</button>}
                        {activeActivityTab === 'payments' && <button className="text-button" type="button" onClick={() => onNavigate('payments')}>Open finance →</button>}
                        {activeActivityTab === 'appointments' && <button className="text-button" type="button" onClick={() => onNavigate('appointments')}>Open appointments →</button>}
                        {activeActivityTab === 'clinical' && <button className="text-button" type="button" onClick={() => onNavigate('clinical')}>Open clinical care →</button>}
                        {activeActivityTab === 'loyalty' && <button className="text-button" type="button" onClick={() => onNavigate('growth')}>Open rewards →</button>}
                      </div>
                    </div>
                    <div className="customer-activity-body">
                      {activeActivityTab === 'orders' && (activity.orders.length ? (
                        <ul className="customer-activity-list">
                          {activity.orders.map((order) => (
                            <li key={order.id}>
                              <div className="customer-activity-main">
                                <div className="customer-activity-title">
                                  <strong>{order.order_number}</strong>
                                  <span className="role-chip">{formatAction(order.status)}</span>
                                  <span className="role-chip">{formatAction(order.fulfillment_type)}</span>
                                </div>
                                <small>{formatDate(order.created_at)} · {order.branch_name ?? 'Branch'} · Payment {order.payment_status.replaceAll('_', ' ')}</small>
                                {order.lines.length > 0 && <small className="customer-activity-items">{order.lines.map((line) => `${line.quantity} × ${line.name_snapshot}`).join(' · ')}</small>}
                                {order.return_status !== 'none' && <small className="customer-activity-warning">Return {formatAction(order.return_status)}{order.return_reason ? ` — ${order.return_reason}` : ''}</small>}
                              </div>
                              <div className="customer-activity-amount">
                                <strong>{formatMoneyValue(order.total_amount, order.currency_code)}</strong>
                                <span className={`status-pill ${order.payment_status === 'paid' ? 'status-active' : 'status-inactive'}`}><i />{formatAction(order.payment_status)}</span>
                                {activity.capabilities.manage.orders && nextOrderStatusActions(order).length > 0 && (
                                  <div className="customer-activity-actions">
                                    {nextOrderStatusActions(order).map((action) => <button key={action.status} className={`text-button ${action.status === 'cancelled' ? 'appointment-cancel' : ''}`} type="button" disabled={activityBusy === `order-${order.id}`} onClick={() => void setOrderStatus(order.id, action.status)}>{action.label}</button>)}
                                  </div>
                                )}
                                {activity.capabilities.manage.returns && order.return_status === 'requested' && (
                                  <div className="customer-activity-actions">
                                    <button className="text-button" type="button" disabled={activityBusy === `return-${order.id}`} onClick={() => void decideOrderReturn(order.id, 'approve')}>Approve return</button>
                                    <button className="text-button appointment-cancel" type="button" disabled={activityBusy === `return-${order.id}`} onClick={() => void decideOrderReturn(order.id, 'reject')}>Reject return</button>
                                  </div>
                                )}
                              </div>
                            </li>
                          ))}
                        </ul>
                      ) : <p className="muted">No online orders are linked to this customer in your branch scope.</p>)}
                      {activeActivityTab === 'pos' && (activity.pos_sales.length ? (
                        <ul className="customer-activity-list">
                          {activity.pos_sales.map((sale) => (
                            <li key={sale.id}>
                              <div className="customer-activity-main">
                                <div className="customer-activity-title">
                                  <strong>{sale.receipt_number}</strong>
                                  <span className="role-chip">{formatAction(sale.status)}</span>
                                </div>
                                <small>{formatDate(sale.sold_at)} · {sale.branch_name ?? 'Branch'}{sale.cashier_name ? ` · ${sale.cashier_name}` : ''}</small>
                                {sale.lines.length > 0 && <small className="customer-activity-items">{sale.lines.map((line) => `${line.quantity} × ${line.item_name}`).join(' · ')}</small>}
                                {sale.payments.length > 0 && <small>Tendered: {sale.payments.map((payment) => `${formatAction(payment.tender_type)} ${formatMoneyValue(payment.amount, sale.currency_code)}`).join(' · ')}</small>}
                                {Number(sale.refunded_amount) > 0 && <small className="customer-activity-warning">Refunded {formatMoneyValue(sale.refunded_amount, sale.currency_code)}</small>}
                              </div>
                              <div className="customer-activity-amount">
                                <strong>{formatMoneyValue(sale.total_amount, sale.currency_code)}</strong>
                                {activity.capabilities.manage.pos_refunds && sale.status === 'completed' && <div className="customer-activity-actions"><button className="text-button appointment-cancel" type="button" onClick={() => setRefundTarget(sale)}>Refund sale</button></div>}
                              </div>
                            </li>
                          ))}
                        </ul>
                      ) : <p className="muted">No in-store POS purchases are linked to this customer in your branch scope.</p>)}
                      {activeActivityTab === 'payments' && (activity.payments.length ? (
                        <ul className="customer-activity-list">
                          {activity.payments.map((payment) => (
                            <li key={payment.key}>
                              <div className="customer-activity-main">
                                <div className="customer-activity-title">
                                  <strong>{payment.order_number ?? payment.receipt_number ?? payment.reference ?? 'Payment'}</strong>
                                  <span className="role-chip">{payment.source_label}</span>
                                </div>
                                <small>{formatDate(payment.occurred_at)} · {payment.provider.replaceAll('_', ' ')} · {payment.method.replaceAll('_', ' ')}{payment.reference && payment.reference !== payment.order_number && payment.reference !== payment.receipt_number ? ` · Ref ${payment.reference}` : ''}</small>
                              </div>
                              <div className="customer-activity-amount">
                                <strong>{formatMoneyValue(payment.amount, payment.currency_code)}</strong>
                                <span className={`status-pill ${payment.status === 'paid' ? 'status-active' : 'status-inactive'}`}><i />{formatAction(payment.status)}</span>
                                {activity.capabilities.manage.payments && payment.source === 'online_order' && payment.transaction_id !== null && payment.settled_at === null && ['paid', 'reversed', 'refunded'].includes(payment.status) && <div className="customer-activity-actions"><button className="text-button" type="button" onClick={() => setSettlementTarget(payment)}>Reconcile settlement</button></div>}
                              </div>
                            </li>
                          ))}
                        </ul>
                      ) : <p className="muted">No payment transactions are linked to this customer in your branch scope.</p>)}
                      {activeActivityTab === 'appointments' && (activity.appointments.length ? (
                        <ul className="customer-activity-list">
                          {activity.appointments.map((appointment) => (
                            <li key={appointment.id}>
                              <div className="customer-activity-main">
                                <div className="customer-activity-title">
                                  <strong>{appointment.service_name ?? 'Appointment'}</strong>
                                  <span className="role-chip">{formatAction(appointment.status)}</span>
                                </div>
                                <small>{formatDate(appointment.starts_at)} · {appointment.branch_name ?? 'Branch'} · {appointment.appointment_number}</small>
                                {Number(appointment.deposit_due) > 0 && <small>Deposit {formatMoneyValue(appointment.deposit_due, appointment.currency_code)} · {formatAction(appointment.deposit_status)}</small>}
                              </div>
                              <div className="customer-activity-amount">
                                <strong>{formatMoneyValue(appointment.service_price, appointment.currency_code)}</strong>
                                <div className="customer-activity-actions">
                                  {activity.capabilities.manage.appointment_status && appointment.status === 'confirmed' && <button className="text-button" type="button" disabled={activityBusy?.startsWith(`appointment-${appointment.id}`)} onClick={() => void transitionAppointment(appointment.id, 'check_in')}>Check in</button>}
                                  {activity.capabilities.manage.appointment_status && appointment.status === 'checked_in' && <button className="text-button" type="button" disabled={activityBusy?.startsWith(`appointment-${appointment.id}`)} onClick={() => void transitionAppointment(appointment.id, 'start')}>Start</button>}
                                  {activity.capabilities.manage.appointment_status && appointment.status === 'in_service' && <button className="text-button" type="button" disabled={activityBusy?.startsWith(`appointment-${appointment.id}`)} onClick={() => void transitionAppointment(appointment.id, 'complete')}>Complete</button>}
                                  {activity.capabilities.manage.appointment_status && appointment.status === 'confirmed' && <button className="text-button" type="button" disabled={activityBusy?.startsWith(`appointment-${appointment.id}`)} onClick={() => void transitionAppointment(appointment.id, 'no_show')}>No show</button>}
                                  {activity.capabilities.manage.appointment_status && ['confirmed', 'awaiting_deposit'].includes(appointment.status) && <button className="text-button" type="button" onClick={() => setRescheduleTarget(appointment)}>Reschedule</button>}
                                  {activity.capabilities.manage.appointment_deposits && appointment.deposit_status === 'pending' && <button className="text-button" type="button" onClick={() => setDepositTarget(appointment)}>Record deposit</button>}
                                  {activity.capabilities.manage.appointment_status && ['requested', 'pending_approval', 'awaiting_deposit', 'confirmed', 'checked_in'].includes(appointment.status) && <button className="text-button appointment-cancel" type="button" onClick={() => setCancelTarget(appointment)}>Cancel</button>}
                                </div>
                              </div>
                            </li>
                          ))}
                        </ul>
                      ) : <p className="muted">No appointments are linked to this customer in your branch scope.</p>)}
                      {activeActivityTab === 'clinical' && (activity.clinical_encounters.length ? (
                        <div className="customer-clinical-list">
                          {activity.clinical_encounters.map((encounter) => (
                            <details key={encounter.id} className="customer-clinical-entry">
                              <summary>
                                <span><strong>{formatDate(encounter.started_at)}</strong><small>{encounter.branch_name ?? 'Branch'}{encounter.clinician_name ? ` · ${encounter.clinician_name}` : ''}{encounter.appointment_number ? ` · ${encounter.appointment_number}` : ''}</small></span>
                                <span className="role-chip">{formatAction(encounter.status)}</span>
                              </summary>
                              <div className="customer-clinical-body">
                                {(activity.capabilities.manage.clinical || activity.capabilities.manage.clinical_amendments) && (
                                  <div className="customer-activity-actions">
                                    {activity.capabilities.manage.clinical && encounter.status === 'in_progress' && <button className="text-button" type="button" onClick={() => setCompleteTarget(encounter)}>Complete encounter</button>}
                                    {activity.capabilities.manage.clinical_amendments && <button className="text-button" type="button" onClick={() => setClinicalDialog({ mode: 'amend', encounter })}>Amend record</button>}
                                  </div>
                                )}
                                {encounter.treatment_plan ? <div><h4>Treatment plan</h4><p>{encounter.treatment_plan}</p></div> : <p className="muted">No treatment plan recorded for this encounter.</p>}
                                {encounter.clinical_note && <div><h4>Clinical note</h4><p>{encounter.clinical_note}</p></div>}
                                {encounter.follow_up_at && <div><h4>Follow-up</h4><p>{formatDate(encounter.follow_up_at)}{encounter.follow_up_notes ? ` — ${encounter.follow_up_notes}` : ''}</p></div>}
                              </div>
                            </details>
                          ))}
                        </div>
                      ) : <p className="muted">{activity.patient_profile_linked ? 'No clinical encounters are linked to this customer in your branch scope.' : 'Create a patient profile to record clinical encounters and treatment plans.'}</p>)}
                      {activeActivityTab === 'loyalty' && (activity.loyalty?.account ? (
                        <div className="customer-loyalty">
                          <div className="customer-loyalty-balance"><strong>{activity.loyalty.account.points_balance} pts</strong><span>{activity.loyalty.account.tier} tier · enrolled {formatDate(activity.loyalty.account.created_at)}</span></div>
                          {activity.loyalty.entries.length > 0 ? (
                            <ul className="customer-activity-list">
                              {activity.loyalty.entries.map((entry, index) => (
                                <li key={`${entry.created_at}-${index}`}>
                                  <div className="customer-activity-main">
                                    <div className="customer-activity-title"><strong>{formatAction(entry.event_type)}</strong><span className="role-chip">{formatAction(entry.source_type)}</span></div>
                                    <small>{formatDate(entry.created_at)}{entry.reason ? ` · ${entry.reason}` : ''}</small>
                                  </div>
                                  <div className="customer-activity-amount"><strong className={entry.points_delta >= 0 ? 'customer-points-earned' : 'customer-points-spent'}>{entry.points_delta >= 0 ? '+' : ''}{entry.points_delta} pts</strong></div>
                                </li>
                              ))}
                            </ul>
                          ) : <p className="muted">No reward activity recorded.</p>}
                        </div>
                      ) : <p className="muted">No loyalty account is linked to this customer.</p>)}
                    </div>
                  </>
                ) : <p className="muted">No commerce, appointment or clinical records are available to your role.</p>}
              </>
            )}
          </section>
        </div>
      )}
      {consentOpen && customer && (
        <ConsentRegistration
          customerId={customerId}
          branches={branches}
          defaultBranchId={customer.registered_branch_id}
          canManagePatients={canManagePatients}
          onClose={() => setConsentOpen(false)}
          onSaved={() => { setConsentOpen(false); void refreshCustomer(); onRefresh() }}
        />
      )}
      {editOpen && customer && (
        <CustomerEditDialog
          customer={customer}
          onClose={() => setEditOpen(false)}
          onSaved={() => { setEditOpen(false); setError(''); setNotice('Customer details were updated.'); void refreshCustomer(); onRefresh() }}
        />
      )}
      {patientOpen && customer && (
        <PatientProfileDialog
          customerId={customer.id}
          profile={customer.patient_profile ?? null}
          onClose={() => setPatientOpen(false)}
          onSaved={() => { setPatientOpen(false); setError(''); setNotice('Patient profile saved.'); void refreshCustomer(); onRefresh() }}
        />
      )}
      {mergeOpen && customer && (
        <MergeCustomerDialog
          customer={customer}
          onClose={() => setMergeOpen(false)}
          onMerged={(targetId) => { setMergeOpen(false); onRefresh(); onSelectCustomer(targetId) }}
        />
      )}
      {bookingOpen && customer && (
        <AppointmentBookingDialog
          user={user}
          branches={branches}
          customer={{ id: customer.id, name: customer.name }}
          onClose={() => setBookingOpen(false)}
          onSaved={() => { setBookingOpen(false); finishActivityAction('Appointment booked.') }}
        />
      )}
      {cancelTarget && (
        <AppointmentCancelDialog
          appointment={cancelTarget}
          onClose={() => setCancelTarget(null)}
          onSaved={() => { setCancelTarget(null); finishActivityAction('Appointment cancelled.') }}
        />
      )}
      {depositTarget && (
        <AppointmentDepositDialog
          appointment={depositTarget}
          onClose={() => setDepositTarget(null)}
          onSaved={() => { setDepositTarget(null); finishActivityAction('Deposit recorded.') }}
        />
      )}
      {rescheduleTarget && (
        <AppointmentRescheduleDialog
          appointment={rescheduleTarget}
          onClose={() => setRescheduleTarget(null)}
          onSaved={() => { setRescheduleTarget(null); finishActivityAction('Appointment rescheduled.') }}
        />
      )}
      {refundTarget && (
        <PosRefundDialog
          sale={refundTarget}
          onClose={() => setRefundTarget(null)}
          onSaved={(message) => { setRefundTarget(null); finishActivityAction(message) }}
        />
      )}
      {clinicalDialog && customer && (
        <ClinicalEncounterDialog
          mode={clinicalDialog.mode}
          customerId={customer.id}
          customerName={customer.name}
          branches={branches}
          appointments={activity?.appointments ?? []}
          encounter={clinicalDialog.mode === 'amend' ? clinicalDialog.encounter : undefined}
          onClose={() => setClinicalDialog(null)}
          onSaved={(message) => { setClinicalDialog(null); finishActivityAction(message) }}
        />
      )}
      {completeTarget && (
        <ClinicalCompleteDialog
          encounter={completeTarget}
          onClose={() => setCompleteTarget(null)}
          onSaved={(message) => { setCompleteTarget(null); finishActivityAction(message) }}
        />
      )}
      {loyaltyOpen && customer && (
        <LoyaltyAdjustDialog
          customerId={customer.id}
          customerName={customer.name}
          balance={activity?.summary.loyalty_points ?? 0}
          onClose={() => setLoyaltyOpen(false)}
          onSaved={(message) => { setLoyaltyOpen(false); finishActivityAction(message) }}
        />
      )}
      {settlementTarget && (
        <PaymentSettlementDialog
          payment={settlementTarget}
          onClose={() => setSettlementTarget(null)}
          onSaved={(message) => { setSettlementTarget(null); finishActivityAction(message) }}
        />
      )}
    </div>
  )
}

function CustomerEditDialog({
  customer,
  onClose,
  onSaved,
}: {
  customer: CustomerRecord
  onClose: () => void
  onSaved: () => void
}) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const emailLocked = Boolean(customer.portal_linked)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest<CustomerRecord>(`/customers/${customer.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          name: String(form.get('name') ?? ''),
          email: emailLocked ? (customer.email ?? null) : String(form.get('email') ?? '') || null,
          phone: String(form.get('phone') ?? '') || null,
          preferred_channel: String(form.get('preferred_channel') ?? 'none'),
          status: String(form.get('status') ?? 'active'),
        }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="customer-edit-title">
        <div className="dialog-heading"><div><span className="eyebrow">CUSTOMER IDENTITY</span><h2 id="customer-edit-title">Edit customer</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Full name<input name="name" required maxLength={255} autoComplete="name" defaultValue={customer.name} /></label>
          <div className="customer-form-grid">
            <label>Email<input name="email" type="email" maxLength={255} autoComplete="email" defaultValue={customer.email ?? ''} disabled={emailLocked} />{emailLocked && <span className="form-hint">Portal-linked email changes require account recovery.</span>}</label>
            <label>Phone<input name="phone" type="tel" maxLength={40} autoComplete="tel" defaultValue={customer.phone ?? ''} /></label>
          </div>
          <div className="customer-form-grid">
            <label>Preferred contact<select name="preferred_channel" defaultValue={customer.preferred_channel}><option value="none">No preference</option><option value="email">Email</option><option value="sms">SMS</option><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option></select></label>
            <label>Status<select name="status" defaultValue={customer.status === 'inactive' ? 'inactive' : 'active'}><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
          </div>
          <p className="form-hint">Deactivating a customer suspends portal access and signs the customer out. Reactivating restores portal access when an account is linked.</p>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</button></div>
        </form>
      </section>
    </div>
  )
}

function PatientProfileDialog({
  customerId,
  profile,
  onClose,
  onSaved,
}: {
  customerId: number
  profile: CustomerRecord['patient_profile']
  onClose: () => void
  onSaved: () => void
}) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest(`/customers/${customerId}/patient-profile`, {
        method: 'PUT',
        body: JSON.stringify({
          date_of_birth: String(form.get('date_of_birth') ?? '') || null,
          emergency_contact_name: String(form.get('emergency_contact_name') ?? '') || null,
          emergency_contact_phone: String(form.get('emergency_contact_phone') ?? '') || null,
          emergency_contact_relationship: String(form.get('emergency_contact_relationship') ?? '') || null,
          ...(profile ? { is_active: form.get('is_active') === 'on' } : {}),
        }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="patient-profile-title">
        <div className="dialog-heading"><div><span className="eyebrow">PATIENT RECORD</span><h2 id="patient-profile-title">{profile ? 'Edit patient profile' : 'Create patient profile'}</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {profile && <p className="muted">Patient number {profile.patient_number}</p>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Date of birth<input name="date_of_birth" type="date" defaultValue={profile?.date_of_birth ?? ''} max={new Date().toISOString().slice(0, 10)} /></label>
          <div className="customer-form-grid">
            <label>Emergency contact<input name="emergency_contact_name" maxLength={255} defaultValue={profile?.emergency_contact_name ?? ''} /></label>
            <label>Contact phone<input name="emergency_contact_phone" type="tel" maxLength={40} defaultValue={profile?.emergency_contact_phone ?? ''} /></label>
          </div>
          <label>Relationship<input name="emergency_contact_relationship" maxLength={60} defaultValue={profile?.emergency_contact_relationship ?? ''} /></label>
          {profile && <label className="customer-check"><input name="is_active" type="checkbox" defaultChecked={profile.is_active} /> Patient record is active</label>}
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save patient profile'}</button></div>
        </form>
      </section>
    </div>
  )
}

function MergeCustomerDialog({
  customer,
  onClose,
  onMerged,
}: {
  customer: CustomerRecord
  onClose: () => void
  onMerged: (targetCustomerId: number) => void
}) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<CustomerRecord[] | null>(null)
  const [searching, setSearching] = useState(false)
  const [targetId, setTargetId] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [mergedTarget, setMergedTarget] = useState<{ id: number; name: string } | null>(null)

  async function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (query.trim().length < 2) {
      setError('Enter at least two characters to search for the profile to retain.')
      return
    }
    setSearching(true)
    setError('')
    try {
      const result = await apiRequest<Paginated<CustomerRecord>>(`/customers?search=${encodeURIComponent(query.trim())}`)
      setResults(result.data.filter((item) => item.id !== customer.id && item.status !== 'merged'))
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setSearching(false)
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (targetId === null) {
      setError('Select the customer profile to retain before merging.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const result = await apiRequest<{ message: string; customer: CustomerRecord }>(`/customers/${customer.id}/merge`, {
        method: 'POST',
        body: JSON.stringify({ target_customer_id: targetId, reason: reason.trim() }),
      })
      setMergedTarget({ id: result.customer.id, name: result.customer.name })
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const closeAfterMerge = () => {
    if (mergedTarget) onMerged(mergedTarget.id)
    else onClose()
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) closeAfterMerge() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="merge-customer-title">
        <div className="dialog-heading"><div><span className="eyebrow">IDENTITY REVIEW</span><h2 id="merge-customer-title">Merge customer profile</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={closeAfterMerge}>×</button></div>
        {mergedTarget ? (
          <div className="content-stack">
            <div className="notice info" role="status">Profiles merged. The retained profile is {mergedTarget.name}; the current record is archived for audit.</div>
            <div className="dialog-actions"><button className="button button-primary" type="button" onClick={() => onMerged(mergedTarget.id)}>Open retained profile</button></div>
          </div>
        ) : (
          <>
            {error && <div className="notice error" role="alert">{error}</div>}
            <p className="muted">Merge <strong>{customer.name}</strong> into the profile that should be retained. Branch history, consents, the patient profile, and any portal account move to the retained profile. The current record is kept as an audited source.</p>
            <p className="form-hint">Both profiles cannot have linked patient records or portal accounts. Resolve those before merging.</p>
            <form className="customer-form" onSubmit={(event) => void search(event)}>
              <label>Find the profile to retain<input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search name, email or phone" /></label>
              <div className="dialog-actions"><button className="button button-secondary" type="submit" disabled={searching}>{searching ? 'Searching…' : 'Search profiles'}</button></div>
            </form>
            {results && (results.length ? <ul className="merge-result-list">{results.map((item) => <li key={item.id}><label><input type="radio" name="merge_target" checked={targetId === item.id} onChange={() => setTargetId(item.id)} /><span><strong>{item.name}</strong><small>{[item.email, item.phone, item.registered_branch?.name].filter(Boolean).join(' · ') || 'No contact details'}</small></span></label></li>)}</ul> : <p className="muted">No other matching customer profiles found.</p>)}
            <form className="customer-form" onSubmit={(event) => void submit(event)}>
              <label>Reason for merging<textarea value={reason} onChange={(event) => setReason(event.target.value)} required minLength={3} maxLength={500} placeholder="Explain why these records are the same person" /></label>
              <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy || targetId === null || reason.trim().length < 3}>{busy ? 'Merging…' : 'Merge into retained profile'}</button></div>
            </form>
          </>
        )}
      </section>
    </div>
  )
}

function ConsentRegistration({
  customerId,
  branches,
  defaultBranchId,
  canManagePatients,
  onClose,
  onSaved,
}: {
  customerId: number
  branches: Branch[]
  defaultBranchId: number | null
  canManagePatients: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest(`/customers/${customerId}/consents`, { method: 'POST', body: form })
      onSaved()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Could not save this consent.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="consent-title">
        <div className="dialog-heading"><div><span className="eyebrow">CONSENT RECORD</span><h2 id="consent-title">Record consent</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        <form className="customer-form" onSubmit={(event) => void submit(event)}>
          <label>Consent type<select name="consent_type" defaultValue="privacy_notice"><option value="privacy_notice">Privacy notice</option><option value="treatment">Treatment</option><option value="marketing">Marketing</option>{canManagePatients && <option value="clinical_photography">Clinical photography</option>}</select></label>
          <div className="customer-form-grid"><label>Status<select name="status" defaultValue="granted"><option value="granted">Granted</option><option value="declined">Declined</option><option value="revoked">Revoked</option></select></label><label>Capture method<select name="capture_method" defaultValue="electronic"><option value="electronic">Electronic</option><option value="signed_form">Signed form</option><option value="verbal">Verbal</option><option value="other">Other</option></select></label></div>
          {branches.length > 0 && <label>Branch<select name="branch_id" defaultValue={defaultBranchId ?? ''}><option value="">Default branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
          <label>Supporting document (PDF/JPG/PNG, max 10 MB)<input name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" /></label>
          <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save consent'}</button></div>
        </form>
      </section>
    </div>
  )
}

function CustomerPortal({ user, onSignOut, onShop }: { user: AuthUser; onSignOut: () => void; onShop: () => void }) {
  const [profile, setProfile] = useState<{ name: string; email: string; phone: string | null; preferred_channel: string; status: string } | null>(null)
  const [marketingPreferences, setMarketingPreferences] = useState<{ channel: 'email' | 'sms' | 'whatsapp'; status: 'opted_in' | 'opted_out' | 'not_set' }[]>([])
  const [rewards, setRewards] = useState<{ program: { is_active: boolean } | null; account: { points_balance: number; tier: string } | null; entries: { event_type: string; points_delta: number; reason: string | null; created_at: string }[] } | null>(null)
  const [appointments, setAppointments] = useState<AppointmentRecord[]>([])
  const [orders, setOrders] = useState<EcommerceOrder[]>([])
  const [notifications, setNotifications] = useState<InboxNotification[]>([])
  const [notificationUnread, setNotificationUnread] = useState(0)
  const [notificationFilter, setNotificationFilter] = useState<'all' | 'unread'>('all')
  const [messageOpen, setMessageOpen] = useState(false)
  const [notificationsLoading, setNotificationsLoading] = useState(true)
  const [reviewSources, setReviewSources] = useState<{ products: { id: number; name: string }[]; services: { id: number; name: string }[] }>({ products: [], services: [] })
  const [reviewKind, setReviewKind] = useState<'product' | 'service'>('product')
  const [loading, setLoading] = useState(true)
  const [appointmentsLoading, setAppointmentsLoading] = useState(true)
  const [ordersLoading, setOrdersLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [bookingOpen, setBookingOpen] = useState(false)

  useEffect(() => {
    void apiRequest<typeof profile>('/portal/profile')
      .then(setProfile)
      .catch((requestError: unknown) => setNotice(requestError instanceof Error ? requestError.message : 'Could not load your profile.'))
      .finally(() => setLoading(false))
    void apiRequest<{ preferences: typeof marketingPreferences }>('/portal/marketing-preferences')
      .then((result) => setMarketingPreferences(result.preferences))
      .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
    void apiRequest<Paginated<AppointmentRecord>>('/portal/appointments')
      .then((result) => setAppointments(result.data))
      .catch((requestError: unknown) => setNotice(requestError instanceof Error ? requestError.message : 'Could not load your appointments.'))
      .finally(() => setAppointmentsLoading(false))
    void apiRequest<EcommerceOrder[]>('/portal/orders')
      .then(setOrders)
      .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
      .finally(() => setOrdersLoading(false))
    void apiRequest<typeof rewards>('/portal/rewards')
      .then(setRewards)
      .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
    void apiRequest<typeof reviewSources>('/portal/review-eligible')
      .then(setReviewSources)
      .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
    void apiRequest<InboxResponse>('/notifications?per_page=50')
      .then((result) => {
        setNotifications(result.data)
        setNotificationUnread(result.unread_count)
      })
      .catch((requestError: unknown) => setNotice(getErrorMessage(requestError)))
      .finally(() => setNotificationsLoading(false))
  }, [])

  async function loadNotifications(filter: 'all' | 'unread') {
    setNotificationFilter(filter)
    setNotificationsLoading(true)
    setNotice('')
    try {
      const result = await apiRequest<InboxResponse>(`/notifications?per_page=50&filter=${filter}`)
      setNotifications(result.data)
      setNotificationUnread(result.unread_count)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setNotificationsLoading(false)
    }
  }

  async function markNotificationRead(id: string) {
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ unread_count: number }>(`/notifications/${id}/read`, { method: 'POST' })
      setNotifications((current) => current.map((item) => item.id === id ? { ...item, read_at: new Date().toISOString() } : item))
      setNotificationUnread(result.unread_count)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function markAllNotificationsRead() {
    setBusy(true)
    setNotice('')
    try {
      await apiRequest('/notifications/read-all', { method: 'POST' })
      setNotifications((current) => current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })))
      setNotificationUnread(0)
      setNotice('All your notifications are marked as read.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function sendClinicMessage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setNotice('')
    try {
      const result = await apiRequest<{ delivered: boolean }>('/mailbox/messages', {
        method: 'POST',
        body: JSON.stringify({
          subject: String(form.get('subject') ?? ''),
          message: String(form.get('message') ?? ''),
          category: 'customer_account',
        }),
      })
      setMessageOpen(false)
      setNotice(result.delivered ? 'Your message was sent to the clinic by email.' : 'Your message was saved, but the clinic could not confirm email delivery.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    const form = new FormData(event.currentTarget)
    try {
      setProfile(await apiRequest('/portal/profile', {
        method: 'PATCH',
        body: JSON.stringify({ phone: String(form.get('phone') ?? '') || null, preferred_channel: String(form.get('preferred_channel') ?? 'none') }),
      }))
      setNotice('Your contact preferences have been saved.')
    } catch (requestError) {
      setNotice(requestError instanceof Error ? requestError.message : 'Could not save your profile.')
    } finally {
      setBusy(false)
    }
  }

  async function saveMarketingPreference(channel: 'email' | 'sms' | 'whatsapp', status: 'opted_in' | 'opted_out') {
    setBusy(true)
    setNotice('')
    try {
      const preference = await apiRequest<{ channel: 'email' | 'sms' | 'whatsapp'; status: 'opted_in' | 'opted_out' }>('/portal/marketing-preferences', {
        method: 'PUT',
        body: JSON.stringify({ channel, status }),
      })
      setMarketingPreferences((current) => current.map((item) => item.channel === channel ? preference : item))
      setNotice(`Your ${channel === 'email' ? 'email' : channel.toUpperCase()} marketing preference has been saved.`)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function cancelAppointment(appointmentId: number) {
    if (!window.confirm('Cancel this appointment? The clinic cancellation policy may restrict late changes.')) return
    setNotice('')
    try {
      await apiRequest(`/portal/appointments/${appointmentId}/cancel`, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'cancel' }),
      })
      const result = await apiRequest<Paginated<AppointmentRecord>>('/portal/appointments')
      setAppointments(result.data)
      setNotice('Your appointment has been cancelled.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function unsubscribeNewsletter() {
    setNotice('')
    try {
      const result = await apiRequest<{ message: string }>('/portal/newsletter/unsubscribe', { method: 'POST' })
      setNotice(result.message)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function submitReview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setNotice('')
    try {
      await apiRequest('/portal/reviews', {
        method: 'POST',
        body: JSON.stringify({
          kind: String(form.get('kind')),
          source_id: Number(form.get('source_id')),
          rating: Number(form.get('rating')),
          review_text: String(form.get('review_text') ?? ''),
          display_name: String(form.get('display_name') ?? ''),
          display_name_consent: form.get('display_name_consent') === 'on',
        }),
      })
      event.currentTarget.reset()
      setNotice('Your verified review was submitted for moderation.')
      setReviewSources(await apiRequest('/portal/review-eligible'))
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  async function updateOrder(order: EcommerceOrder, action: 'cancel' | 'return') {
    const reason = action === 'return' ? window.prompt('Briefly describe why you are requesting a return:')?.trim() : ''
    if (action === 'return' && !reason) return
    if (action === 'cancel' && !window.confirm(`Cancel order ${order.order_number}?`)) return
    setNotice('')
    try {
      if (action === 'cancel') {
        await apiRequest(`/portal/orders/${order.id}/cancel`, { method: 'POST' })
      } else {
        await apiRequest(`/portal/orders/${order.id}/return-request`, {
          method: 'POST',
          body: JSON.stringify({ reason }),
        })
      }
      setOrders(await apiRequest<EcommerceOrder[]>('/portal/orders'))
      setNotice(action === 'cancel'
        ? 'Your order has been cancelled and its reservation released.'
        : 'Your return request has been sent to the clinic. Refund processing is not configured.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    }
  }

  return (
    <main className="portal-shell">
      <header className="portal-header"><a className="brand" href="#portal"><span className="brand-mark">o</span><span>oshel<span className="brand-period">.</span></span></a><div className="storefront-header-actions"><button className="button button-secondary" type="button" onClick={() => document.getElementById('portal-mailbox')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}>Mailbox{notificationUnread > 0 ? ` (${notificationUnread})` : ''}</button><button className="button button-primary" type="button" onClick={onShop}>Shop products</button><button className="button button-secondary" type="button" onClick={onSignOut}>Sign out</button></div></header>
      <section className="panel portal-card"><span className="eyebrow">CUSTOMER PORTAL</span><h1>Your contact profile</h1><p className="muted">Keep your phone number and preferred contact method up to date.</p>
        {loading ? <LoadingState /> : profile && <form className="customer-form portal-form" onSubmit={(event) => void saveProfile(event)}><label>Name<input value={profile.name} readOnly /></label><label>Email<input value={profile.email} readOnly /></label><label>Phone<input name="phone" type="tel" defaultValue={profile.phone ?? ''} maxLength={40} /></label><label>Preferred contact<select name="preferred_channel" defaultValue={profile.preferred_channel}><option value="none">No preference</option><option value="email">Email</option><option value="sms">SMS</option><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option></select></label><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save preferences'}</button></form>}
        {notice && <p className="portal-notice" role="status">{notice}</p>}
      </section>
      <section className="panel portal-card portal-notifications" id="portal-mailbox">
        <div className="welcome-row">
          <div><span className="eyebrow">YOUR UPDATES</span><h2>Your mailbox</h2><p className="muted">Appointment, order, payment, and account updates sent to your email are kept here too.</p></div>
          <div className="dialog-actions">
            <button className="button button-secondary" type="button" onClick={() => setMessageOpen((current) => !current)}>{messageOpen ? 'Close message form' : 'Message the clinic'}</button>
            {notificationUnread > 0 && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void markAllNotificationsRead().then(() => setNotificationFilter('all'))}>Mark all as read</button>}
          </div>
        </div>
        {messageOpen && (
          <form className="form-stack mailbox-reply" onSubmit={(event) => void sendClinicMessage(event)}>
            <label>Subject<input name="subject" required maxLength={160} placeholder="How can we help?" /></label>
            <label>Message<textarea name="message" required rows={4} maxLength={5000} placeholder="Write your message. It is emailed to the clinic." /></label>
            <div className="dialog-actions"><button className="button button-primary" type="submit" disabled={busy}>{busy ? 'Sending…' : 'Send message'}</button></div>
          </form>
        )}
        <div className="storefront-hero-actions" role="tablist" aria-label="Mailbox filter">
          <button className={`button ${notificationFilter === 'all' ? 'button-primary' : 'button-secondary'}`} type="button" role="tab" aria-selected={notificationFilter === 'all'} onClick={() => void loadNotifications('all')}>All messages</button>
          <button className={`button ${notificationFilter === 'unread' ? 'button-primary' : 'button-secondary'}`} type="button" role="tab" aria-selected={notificationFilter === 'unread'} onClick={() => void loadNotifications('unread')}>Unread ({notificationUnread})</button>
        </div>
        {notificationsLoading ? <LoadingState compact /> : notifications.length ? (
          <div className="portal-order-list">{notifications.map((item) => (
            <article className={`portal-appointment ${item.read_at ? '' : 'inbox-unread'}`} key={item.id}>
              <div><strong>{item.title}</strong><span>{item.message}</span><small>{item.group_label}{item.created_at ? ` · ${formatDate(item.created_at)}` : ''}</small></div>
              <div className="storefront-hero-actions">
                {item.url && <a className="text-button" href={item.url}>Open</a>}
                {!item.read_at && <button className="text-button" type="button" disabled={busy} onClick={() => void markNotificationRead(item.id)}>Mark read</button>}
              </div>
            </article>
          ))}</div>
        ) : <EmptyState title={notificationFilter === 'unread' ? 'No unread messages' : 'No messages yet'} message={notificationFilter === 'unread' ? 'You have read every message in your mailbox.' : 'Updates about your appointments, orders, and account will appear here and in your email inbox.'} />}
      </section>
      <section className="panel portal-card portal-appointments">
        <div className="welcome-row">
          <div><span className="eyebrow">YOUR VISITS</span><h2>Your appointments</h2><p className="muted">Review upcoming visits and manage bookings.</p></div>
          {user.permissions.includes('appointments.book.self') && <button className="button button-primary" type="button" onClick={() => setBookingOpen(true)}>＋ Book a visit</button>}
        </div>
        {appointmentsLoading ? <LoadingState compact /> : appointments.length ? (
          <div className="portal-appointment-list">{appointments.map((appointment) => (
            <article className="portal-appointment" key={appointment.id}>
              <div><strong>{appointment.service?.name ?? 'Appointment'}</strong><span>{appointment.branch?.name ?? 'Clinic'} · {formatAppointmentTime(appointment.starts_at, true)}</span><small>{appointment.appointment_number} · {formatAction(appointment.status)}</small></div>
              <div className="storefront-hero-actions">
                <button className="text-button" type="button" onClick={() => void apiDownload(`/documents/appointments/${appointment.id}`, `oshel-appointment-${appointment.appointment_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>PDF</button>
                {user.permissions.includes('appointments.cancel.own') && appointment.status === 'confirmed' && <button className="text-button appointment-cancel" type="button" onClick={() => void cancelAppointment(appointment.id)}>Cancel</button>}
              </div>
            </article>
          ))}</div>
        ) : <EmptyState title="No appointments yet" message="Book a visit with your clinic to see it here." />}
      </section>
      <section className="panel portal-card">
        <div><span className="eyebrow">OSHEL REWARDS</span><h2>Your rewards</h2><p className="muted">Points are earned from eligible completed in-clinic purchases.</p></div>
        {rewards?.program?.is_active ? <><div className="welcome-row"><strong>{(rewards.account?.points_balance ?? 0).toLocaleString()} points</strong><span className="status-pill">{rewards.account?.tier ?? 'Member'}</span></div>{rewards.entries.length > 0 && <div className="portal-order-list">{rewards.entries.slice(0, 10).map((entry, index) => <div className="portal-appointment" key={`${entry.event_type}-${entry.created_at}-${index}`}><span>{formatAction(entry.event_type)}{entry.reason ? ` · ${entry.reason}` : ''}</span><strong>{entry.points_delta > 0 ? '+' : ''}{entry.points_delta}</strong></div>)}</div>}</> : <p className="muted">The rewards programme is not active yet.</p>}
      </section>
      <section className="panel portal-card">
        <div><span className="eyebrow">YOUR PRIVACY</span><h2>Marketing preferences</h2><p className="muted">Choose whether Oshel may contact you with marketing messages. Your choice is recorded; automated marketing delivery is not currently enabled.</p></div>
        <div className="portal-order-list">{marketingPreferences.map((preference) => {
          const channelLabel = preference.channel === 'email' ? 'Email' : preference.channel === 'sms' ? 'SMS' : 'WhatsApp'
          const addressMissing = preference.channel === 'email' ? !profile?.email : !profile?.phone
          return <div className="portal-appointment" key={preference.channel}><div><strong>{channelLabel}</strong><span>Current choice: {preference.status === 'not_set' ? 'Not set' : preference.status === 'opted_in' ? 'Allowed' : 'Opted out'}</span></div><div className="storefront-hero-actions"><button className="button button-secondary" type="button" disabled={busy || addressMissing} onClick={() => void saveMarketingPreference(preference.channel, 'opted_in')}>Allow</button><button className="button button-secondary" type="button" disabled={busy} onClick={() => void saveMarketingPreference(preference.channel, 'opted_out')}>Opt out</button></div></div>
        })}</div>
        <p className="muted">Newsletter signup is managed separately.</p>
        <button className="button button-secondary" type="button" onClick={() => void unsubscribeNewsletter()}>Withdraw newsletter consent</button>
      </section>
      <section className="panel portal-card">
        <div><span className="eyebrow">YOUR EXPERIENCE</span><h2>Share a verified review</h2><p className="muted">Only completed in-clinic purchases and visits are eligible. Your review will be checked before public display.</p></div>
        {reviewSources.products.length || reviewSources.services.length ? <form className="website-content-form" onSubmit={(event) => void submitReview(event)}>
          <label>Review type<select name="kind" required value={reviewKind} onChange={(event) => setReviewKind(event.target.value as 'product' | 'service')}><option value="product">Product purchase</option><option value="service">Completed service</option></select></label>
          <label>Product or service<select name="source_id" required defaultValue=""><option value="" disabled>Select item</option>{(reviewKind === 'product' ? reviewSources.products : reviewSources.services).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label>Rating<select name="rating" required defaultValue="5"><option value="5">5 — Excellent</option><option value="4">4 — Very good</option><option value="3">3 — Good</option><option value="2">2 — Fair</option><option value="1">1 — Poor</option></select></label>
          <label>Your review<textarea name="review_text" required minLength={10} maxLength={2000} rows={4} /></label>
          <label>Display name<input name="display_name" maxLength={120} placeholder="Optional" /></label>
          <label className="storefront-consent-check"><input name="display_name_consent" type="checkbox" /> I consent to showing my chosen name with the review.</label>
          <button className="button button-primary" type="submit">Submit review</button>
        </form> : <p className="muted">No unreviewed completed purchases or visits are available on this account.</p>}
      </section>
      <section className="panel portal-card portal-orders">
        <div className="welcome-row"><div><span className="eyebrow">YOUR ORDERS</span><h2>Online order history</h2><p className="muted">Track order status and manage eligible orders.</p></div><button className="button button-secondary" type="button" onClick={onShop}>Shop products</button></div>
        <div className="notice info">Online payment and refunds are not configured. Unpaid orders reserve stock for 15 minutes; do not make a separate payment.</div>
        {ordersLoading ? <LoadingState compact /> : orders.length ? <div className="portal-order-list">{orders.map((order) => <article className="portal-order" key={order.id}>
          <div className="portal-order-heading"><div><strong>{order.order_number}</strong><span>{order.branch_name ?? 'Clinic'} · {new Date(order.created_at).toLocaleString()}</span></div><span className="status-pill">{formatAction(order.status)}</span></div>
          <div className="portal-order-lines">{order.lines.map((line, index) => <div key={`${line.sku ?? line.name}-${index}`}><span>{line.quantity} × {line.name}</span><strong>{new Intl.NumberFormat('en-KE', { style: 'currency', currency: order.currency_code || 'KES' }).format(Number(line.line_total))}</strong></div>)}</div>
          <div className="portal-order-footer"><span>{order.fulfillment_type === 'delivery' ? 'Delivery' : 'Collection'} · <strong>{new Intl.NumberFormat('en-KE', { style: 'currency', currency: order.currency_code || 'KES' }).format(Number(order.total_amount))}</strong></span><div className="ecommerce-order-actions">
            <button className="text-button" type="button" onClick={() => void apiDownload(`/documents/orders/${order.id}/invoice`, `oshel-invoice-${order.order_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>Invoice PDF</button>
            <button className="text-button" type="button" onClick={() => void apiDownload(`/documents/orders/${order.id}/receipt`, `oshel-receipt-${order.order_number}`).catch((downloadError: unknown) => setNotice(getErrorMessage(downloadError)))}>Receipt PDF</button>
            {order.status === 'awaiting_payment' && <button className="text-button" type="button" onClick={() => void updateOrder(order, 'cancel')}>Cancel order</button>}
            {order.status === 'completed' && order.return_status === 'none' && user.permissions.includes('ecommerce.returns.request') && <button className="text-button" type="button" onClick={() => void updateOrder(order, 'return')}>Request return</button>}
          </div></div>
          {order.return_status !== 'none' && <p className="muted ecommerce-return-note">Return: {formatAction(order.return_status)}{order.return_reason ? ` — ${order.return_reason}` : ''}{order.return_status === 'refund_pending' ? ' · Refund must still be completed by the clinic.' : ''}</p>}
        </article>)}</div> : <EmptyState title="No online orders yet" message="Orders placed through the online shop will appear here." />}
      </section>
      {bookingOpen && <PortalAppointmentBookingDialog branchId={user.branch_id} onClose={() => setBookingOpen(false)} onSaved={() => {
        setBookingOpen(false)
        void apiRequest<Paginated<AppointmentRecord>>('/portal/appointments')
          .then((result) => {
            setAppointments(result.data)
            setNotice('Your appointment has been requested.')
          })
          .catch((requestError: unknown) => setNotice(`Your appointment was booked, but the list could not be refreshed. ${getErrorMessage(requestError)}`))
      }} onWaitlisted={() => { setBookingOpen(false); setNotice('You have been added to the appointment waitlist.') }} />}
    </main>
  )
}

function PortalAppointmentBookingDialog({
  branchId,
  initialServiceId,
  onClose,
  onSaved,
  onWaitlisted,
}: {
  branchId: number | null
  initialServiceId?: number | null
  onClose: () => void
  onSaved: () => void
  onWaitlisted: () => void
}) {
  const [services, setServices] = useState<ServiceRecord[]>([])
  const [serviceId, setServiceId] = useState(initialServiceId ? String(initialServiceId) : '')
  const [date, setDate] = useState(BOOKING_DATE_MIN)
  const [slots, setSlots] = useState<{ starts_at: string; ends_at: string; resources: { id: number; name: string; resource_type: string }[] }[]>([])
  const [selectedSlot, setSelectedSlot] = useState('')
  const [loadingServices, setLoadingServices] = useState(true)
  const [loadingSlots, setLoadingSlots] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!branchId) {
      return
    }
    let active = true
    void apiRequest<ServiceRecord[]>(`/public/services?branch_id=${branchId}`)
      .then((result) => { if (active) setServices(result) })
      .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
      .finally(() => { if (active) setLoadingServices(false) })
    return () => { active = false }
  }, [branchId])

  useEffect(() => {
    if (!branchId || !serviceId || !date) return
    let active = true
    void apiRequest<{ slots: typeof slots }>(`/public/availability?branch_id=${branchId}&service_id=${serviceId}&date=${encodeURIComponent(date)}`)
      .then((result) => { if (active) setSlots(result.slots) })
      .catch((requestError: unknown) => { if (active) setError(getErrorMessage(requestError)) })
      .finally(() => { if (active) setLoadingSlots(false) })
    return () => { active = false }
  }, [branchId, serviceId, date])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!branchId || !slots.some((slot) => slot.starts_at === selectedSlot)) {
      setError('Choose an available appointment time.')
      return
    }
    setBusy(true)
    setError('')
    try {
      await apiRequest('/portal/appointments', {
        method: 'POST',
        body: JSON.stringify({ branch_id: branchId, service_id: Number(serviceId), starts_at: selectedSlot }),
      })
      onSaved()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function joinWaitlist() {
    if (!branchId || !serviceId) return
    setBusy(true)
    setError('')
    try {
      await apiRequest('/portal/waitlist', {
        method: 'POST',
        body: JSON.stringify({ branch_id: branchId, service_id: Number(serviceId), preferred_date: date }),
      })
      onWaitlisted()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <section className="dialog customer-dialog" role="dialog" aria-modal="true" aria-labelledby="portal-booking-title">
        <div className="dialog-heading"><div><span className="eyebrow">ONLINE BOOKING</span><h2 id="portal-booking-title">Book a visit</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        {error && <div className="notice error" role="alert">{error}</div>}
        {!branchId ? <EmptyState title="Booking branch unavailable" message="Contact your clinic to book a visit; this account is not linked to a booking branch." /> : (
          <form className="customer-form" onSubmit={(event) => void submit(event)}>
            <label>Service<select required value={serviceId} onChange={(event) => { setServiceId(event.target.value); setSelectedSlot(''); setSlots([]); setLoadingSlots(Boolean(event.target.value)); setError('') }}><option value="">{loadingServices ? 'Loading services…' : 'Select a service'}</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name} · {service.duration_minutes} min</option>)}</select></label>
            <label>Date<input type="date" required min={BOOKING_DATE_MIN} max={BOOKING_DATE_MAX} value={date} onChange={(event) => { setDate(event.target.value); setSelectedSlot(''); setSlots([]); setLoadingSlots(Boolean(serviceId)); setError('') }} /></label>
            <label>Available time<select required value={selectedSlot} disabled={loadingSlots || !slots.length} onChange={(event) => setSelectedSlot(event.target.value)}><option value="">{loadingSlots ? 'Finding times…' : slots.length ? 'Select an available time' : 'No available times for this date'}</option>{slots.map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{formatAppointmentTime(slot.starts_at)} · {slot.resources.map((resource) => resource.name).join(', ')}</option>)}</select></label>
            <div className="dialog-actions"><button className="button button-secondary" type="button" onClick={onClose}>Cancel</button>{serviceId && !loadingSlots && !slots.length && <button className="button button-secondary" type="button" onClick={() => void joinWaitlist()} disabled={busy}>Join waitlist</button>}<button className="button button-primary" type="submit" disabled={busy || loadingServices || loadingSlots || !slots.length}>{busy ? 'Booking…' : 'Request appointment'}</button></div>
          </form>
        )}
      </section>
    </div>
  )
}

function AnalyticsPage({
  data,
  user,
  branchScope,
  loading,
  onReportLoad,
  onRefresh,
}: {
  data: AnalyticsDashboard | null
  user: AuthUser
  branchScope: string
  loading: boolean
  onReportLoad: (report: AnalyticsDashboard) => void
  onRefresh: () => void
}) {
  const [from, setFrom] = useState(() => new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10))
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10))
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')

  function reportQuery(): string {
    const params = new URLSearchParams({ from, to })
    if (branchScope !== 'all') params.set('branch_id', branchScope)
    return params.toString()
  }

  async function runReport(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setNotice('')
    try {
      const report = await apiRequest<AnalyticsDashboard>(`/analytics/dashboard?${reportQuery()}`)
      onReportLoad(report)
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function exportReport() {
    setBusy(true)
    setNotice('')
    try {
      await apiDownload(`/analytics/export?${reportQuery()}`, `oshel-analytics-${from}-to-${to}.csv`)
      setNotice('Analytics export downloaded and recorded in the audit log.')
    } catch (requestError) {
      setNotice(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  const branchNames = new Map((data?.branches ?? []).map((branch) => [branch.id, branch.name]))

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div><span className="eyebrow">BUSINESS INTELLIGENCE</span><h1>Analytics & reports</h1><p className="muted">Permission-filtered, branch-scoped summaries with documented sources and definitions.</p></div>
        <div className="dialog-actions">
          {user.permissions.includes('analytics.export') && <button className="button button-secondary" type="button" disabled={busy || !data} onClick={() => void exportReport()}>Export CSV</button>}
          <button className="button button-secondary" type="button" disabled={loading || busy} onClick={onRefresh}>Refresh</button>
        </div>
      </div>
      <form className="panel website-content-form marketing-form-grid" onSubmit={(event) => void runReport(event)}>
        <label>From<input type="date" required value={from} max={to} onChange={(event) => setFrom(event.target.value)} /></label>
        <label>To<input type="date" required value={to} min={from} onChange={(event) => setTo(event.target.value)} /></label>
        <label>Branch scope<input value={branchScope === 'all' ? 'All assigned branches' : branchNames.get(Number(branchScope)) ?? 'Selected branch'} readOnly /></label>
        <button className="button button-primary" type="submit" disabled={busy || loading}>Run report</button>
      </form>
      {notice && <div className="notice info" role="status">{notice}</div>}
      {data && <div className="notice info">Period: {formatDate(data.period.from)} – {formatDate(data.period.to)}. Currency totals are kept separate. Reports omit individual customer, employee, and patient records.</div>}
      {!data && <EmptyState title="Analytics unavailable" message="The selected reporting period could not be loaded. Refresh to try again." />}
      {data?.sections.map((section) => (
        <section className="panel content-stack" key={section.key}>
          <div><span className="eyebrow">{section.source}</span><h2>{section.title}</h2><p className="muted">{section.definition}</p></div>
          {section.metrics.length ? <div className="table-scroll"><table className="data-table"><thead><tr><th>Metric</th><th>Value</th><th>Branch</th></tr></thead><tbody>{section.metrics.map((metric, index) => (
            <tr key={`${metric.branch_id ?? 'organization'}-${metric.label}-${metric.currency_code ?? ''}-${index}`}>
              <td><strong>{metric.label}</strong><small>{section.date_basis}</small></td>
              <td>{metric.unit === 'currency' ? `${metric.currency_code ?? ''} ${metric.value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : `${metric.value.toLocaleString()} ${metric.unit}`}{metric.transactions !== undefined && <small>{metric.transactions.toLocaleString()} transactions</small>}{metric.quantity !== undefined && <small>{metric.quantity.toLocaleString()} units on hand</small>}{metric.runs !== undefined && <small>{metric.runs.toLocaleString()} payroll runs</small>}{metric.spend !== undefined && <small>Spend: {metric.currency_code} {metric.spend.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</small>}{metric.impressions !== undefined && <small>{metric.impressions.toLocaleString()} impressions · {metric.clicks?.toLocaleString()} clicks · {metric.conversions?.toLocaleString()} conversions</small>}</td>
              <td>{metric.branch_id === null ? 'Organization-wide' : branchNames.get(metric.branch_id) ?? `Branch ${metric.branch_id}`}</td>
            </tr>
          ))}</tbody></table></div> : <EmptyState compact title="No records in this period" message="No matching source records were found for this metric and scope." />}
          <details><summary>Metric lineage and access</summary><p className="muted">Source: {section.source}. Date basis: {section.date_basis}. Required permission: {section.permission}.</p></details>
        </section>
      ))}
      {data && <details className="panel"><summary>Metric definitions</summary><div className="content-stack">{data.metric_definitions.map((definition) => <p className="muted" key={definition.key}><strong>{definition.title}:</strong> {definition.definition} Source: {definition.source}; date basis: {definition.date_basis}; permission: {definition.permission}.</p>)}</div></details>}
    </div>
  )
}

function OverviewPage({
  user,
  overview,
  branchScope,
  onNavigate,
  onAddStaff,
  onRefresh,
}: {
  user: AuthUser
  overview: Overview | null
  branchScope: string
  onNavigate: (page: PageKey) => void
  onAddStaff: () => void
  onRefresh: () => void
}) {
  if (!overview) {
    return <EmptyState title="Your workspace is getting ready" message="There is no organization data available for this account yet." />
  }

  const scopedBranches = branchScope === 'all'
    ? overview.branches
    : overview.branches.filter((branch) => String(branch.id) === branchScope)
  const scopedDepartments = branchScope === 'all'
    ? overview.departments
    : overview.departments.filter((department) => String(department.branch_id) === branchScope)
  const selectedBranchInfo = overview.branches.find((branch) => String(branch.id) === branchScope)
  const isBranchDashboard = !user.permissions.includes('organization.view')
  const friendlyRole = roleDescriptions[user.roles[0]] ?? user.roles[0] ?? 'Clinic team'
  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">{TODAY_LABEL}</span>
          <h1>Good day, {user.name.split(' ')[0]} <span className="wave">✦</span></h1>
          <p className="muted">{friendlyRole} · {overview.organization?.name ?? 'Your clinic'}</p>
        </div>
        <div className="dialog-actions">
          {user.permissions.includes('users.manage') && user.permissions.includes('roles.assign') && (
            <button className="button button-primary" type="button" onClick={onAddStaff}>＋ Add staff</button>
          )}
          <button className="button button-secondary" type="button" onClick={onRefresh}>
            <span aria-hidden="true">↻</span> Refresh
          </button>
        </div>
      </div>

      <section className="welcome-banner">
        <div className="banner-copy">
          <span className="banner-kicker">A CLEAR VIEW OF YOUR CLINIC</span>
          <h2>{isBranchDashboard ? 'Your branch’s care, at a glance.' : 'Your team’s care, at a glance.'}</h2>
          <p>{isBranchDashboard
            ? 'Here’s what’s happening at your assigned branch today.'
            : `Here’s what’s happening across ${branchScope === 'all' ? 'your workspace' : 'the selected branch'} today.`}</p>
        </div>
        <div className="banner-illustration" aria-hidden="true">
          <div className="sun-disc" />
          <div className="banner-vase"><span /><i /><b /></div>
          <div className="banner-flower flower-a">✳</div>
          <div className="banner-flower flower-b">✳</div>
          <div className="banner-leaf" />
        </div>
      </section>

      <div className="section-heading">
        <div><h2>{isBranchDashboard ? 'Branch overview' : 'Workspace overview'}</h2><p className="muted">{isBranchDashboard ? 'A snapshot of your assigned clinic locations.' : 'A little snapshot of your clinic.'}</p></div>
        <span className="live-indicator"><i /> Live data</span>
      </div>
      <section className="metrics-grid" aria-label="Workspace statistics">
        {user.permissions.includes('organization.view') && (
          <MetricCard label="Organizations" value={overview.stats.organizations} icon="⌂" tone="lavender" note="Your active workspace" />
        )}
        {user.permissions.includes('branch.view') && (
          <MetricCard label="Branches" value={branchScope === 'all' ? overview.stats.branches : scopedBranches.length} icon="⌖" tone="sage" note={isBranchDashboard ? 'Assigned to you' : 'Across your organization'} />
        )}
        {user.permissions.includes('department.view') && (
          <MetricCard label="Departments" value={branchScope === 'all' ? overview.stats.departments : selectedBranchInfo?.departments_count ?? scopedDepartments.length} icon="▦" tone="peach" note="Care teams & services" />
        )}
        {user.permissions.includes('users.view') && overview.stats.users !== null && (
          <MetricCard label="Staff" value={overview.stats.users} icon="♙" tone="blue" note="People in your workspace" />
        )}
      </section>

      <div className="overview-columns">
        {user.permissions.includes('branch.view') && (
          <section className="panel">
            <div className="panel-heading">
              <div><h2>Branches</h2><p className="muted">Your clinic locations</p></div>
              <button className="text-button" type="button" onClick={() => onNavigate('branches')}>View all <span aria-hidden="true">→</span></button>
            </div>
            {scopedBranches.length > 0 ? (
              <div className="branch-list">
                {scopedBranches.slice(0, 4).map((branch) => (
                  <div className="branch-row" key={branch.id}>
                    <span className="branch-symbol">⌖</span>
                    <span className="branch-info"><strong>{branch.name}</strong><small>{branch.city || branch.code}</small></span>
                    <span className={`status-pill ${branch.is_active ? 'status-active' : 'status-inactive'}`}>
                      <i />{branch.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState title="No branches to show" message="Branch locations will appear here when available." compact />
            )}
          </section>
        )}
        {user.permissions.includes('department.view') && (
          <section className="panel">
            <div className="panel-heading">
              <div><h2>Departments</h2><p className="muted">Teams across your clinic</p></div>
              <button className="text-button" type="button" onClick={() => onNavigate('departments')}>View all <span aria-hidden="true">→</span></button>
            </div>
            {scopedDepartments.length > 0 ? (
              <div className="department-list">
                {scopedDepartments.slice(0, 4).map((department, index) => (
                  <div className="department-row" key={department.id}>
                    <span className={`department-symbol department-tone-${index % 4}`}>✳</span>
                    <span className="branch-info"><strong>{department.name}</strong><small>{department.branch?.name ?? 'Organization-wide'}</small></span>
                    <span className="department-code">{department.code}</span>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState title="No departments to show" message="Departments will appear here when configured." compact />
            )}
          </section>
        )}
      </div>

      {user.permissions.includes('branch.view') && user.permissions.includes('department.view') && (
        <BranchDistribution branches={scopedBranches} />
      )}

      {user.permissions.includes('users.view') && (
        <section className="quick-actions">
          <span className="quick-action-spark">✳</span>
          <div><strong>Good work starts with a connected team.</strong><p className="muted">Review your team access and keep branch assignments up to date.</p></div>
          <button className="button button-secondary" type="button" onClick={() => onNavigate('users')}>Manage team <span aria-hidden="true">→</span></button>
        </section>
      )}
    </div>
  )
}

function BranchDistribution({ branches }: { branches: Branch[] }) {
  const colors = ['#805a40', '#b17a50', '#d2a578', '#807d67', '#a8a186', '#6f8372']
  const totalDepartments = branches.reduce((total, branch) => total + (branch.departments_count ?? 0), 0)
  const maxDepartments = Math.max(...branches.map((branch) => branch.departments_count ?? 0), 1)
  const gradient = branches.reduce<{ position: number; segments: string[] }>((result, branch, index) => {
    const share = totalDepartments > 0 ? ((branch.departments_count ?? 0) / totalDepartments) * 100 : 0
    const end = result.position + share
    return {
      position: end,
      segments: [...result.segments, `${colors[index % colors.length]} ${result.position}% ${end}%`],
    }
  }, { position: 0, segments: [] }).segments.join(', ')

  if (branches.length === 0 || totalDepartments === 0) return null

  return (
    <div className="analytics-grid">
      <section className="panel analytics-panel">
        <div className="panel-heading">
          <div><h2>Department footprint</h2><p className="muted">How your teams are distributed by branch</p></div>
          <span className="chart-period">BY LOCATION</span>
        </div>
        <div className="distribution-list">
          {branches.map((branch, index) => {
            const count = branch.departments_count ?? 0
            return (
              <div className="distribution-row" key={branch.id}>
                <span className="distribution-dot" style={{ backgroundColor: colors[index % colors.length] }} />
                <span className="distribution-name">{branch.name}</span>
                <span className="distribution-track"><i style={{ width: `${(count / maxDepartments) * 100}%`, backgroundColor: colors[index % colors.length] }} /></span>
                <strong className="distribution-count">{count}</strong>
              </div>
            )
          })}
        </div>
        <p className="chart-footnote">Based on {totalDepartments} branch-linked {totalDepartments === 1 ? 'department' : 'departments'}.</p>
      </section>
      <section className="panel coverage-panel">
        <div className="panel-heading">
          <div><h2>Clinic coverage</h2><p className="muted">Active branch departments</p></div>
        </div>
        <div className="coverage-content">
          <div className="donut-chart" style={{ background: `conic-gradient(${gradient})` }} role="img" aria-label={`${totalDepartments} departments distributed across ${branches.length} branches`}>
            <div className="donut-center"><strong>{totalDepartments}</strong><span>DEPARTMENTS</span></div>
          </div>
          <div className="coverage-legend">
            {branches.slice(0, 5).map((branch, index) => (
              <div className="legend-row" key={branch.id}>
                <i style={{ backgroundColor: colors[index % colors.length] }} />
                <span>{branch.name}</span><strong>{branch.departments_count ?? 0}</strong>
              </div>
            ))}
            {branches.length > 5 && <small className="muted">+{branches.length - 5} more branches</small>}
          </div>
        </div>
      </section>
    </div>
  )
}

function ClinicProfilePage({
  data,
  user,
  onRefresh,
}: {
  data: OrganizationRecord | null
  user: AuthUser
  onRefresh: () => void
}) {
  const canManage = user.permissions.includes('organization.manage')
  const [form, setForm] = useState({
    name: '',
    business_type: '',
    email: '',
    phone: '',
    city: '',
    address: '',
    timezone: '',
    currency_code: '',
    country_code: '',
    registration_number: '',
  })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    if (!data) return
    setForm({
      name: data.name ?? '',
      business_type: data.business_type ?? '',
      email: data.email ?? '',
      phone: data.phone ?? '',
      city: data.city ?? '',
      address: data.address ?? '',
      timezone: data.timezone ?? '',
      currency_code: data.currency_code ?? '',
      country_code: data.country_code ?? '',
      registration_number: data.registration_number ?? '',
    })
  }, [data])

  function field(key: keyof typeof form) {
    return {
      value: form[key],
      disabled: !canManage,
      onChange: (event: ChangeEvent<HTMLInputElement>) => setForm((current) => ({ ...current, [key]: event.target.value })),
    }
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!data || !canManage) return

    setBusy(true)
    setError('')
    setNotice('')

    const payload = Object.fromEntries(
      Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]),
    )

    try {
      await apiRequest(`/organizations/${data.id}`, { method: 'PUT', body: JSON.stringify(payload) })
      setNotice('Clinic profile saved.')
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="content-stack settings-page">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">ADMINISTRATION</span>
          <h1>Clinic profile</h1>
          <p className="muted">{moduleDescription('organization')}</p>
        </div>
        <div className="welcome-actions">
          <button className="button button-secondary" type="button" onClick={onRefresh}>↻ Refresh</button>
        </div>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      {error && <div className="notice error" role="alert">{error}</div>}
      {!data ? (
        <EmptyState title="No clinic profile available" message="Your account is not linked to an organization profile yet." />
      ) : (
        <form className="panel settings-panel" onSubmit={(event) => void save(event)}>
          <div className="panel-heading">
            <div>
              <h2>Clinic identity</h2>
              <p>These details appear across staff screens and customer communications.</p>
            </div>
            <span className="code-chip">{data.slug}</span>
          </div>
          <div className="settings-form-grid">
            <label className="field"><span>Clinic name</span><input required maxLength={255} {...field('name')} /></label>
            <label className="field"><span>Business type</span><input maxLength={100} placeholder="Skin care and beauty clinic" {...field('business_type')} /></label>
            <label className="field"><span>Email address</span><input type="email" maxLength={255} {...field('email')} /></label>
            <label className="field"><span>Phone number</span><input maxLength={40} {...field('phone')} /></label>
            <label className="field"><span>City</span><input maxLength={120} {...field('city')} /></label>
            <label className="field"><span>Registration number</span><input maxLength={120} {...field('registration_number')} /></label>
            <label className="field"><span>Timezone</span><input maxLength={64} placeholder="Africa/Nairobi" {...field('timezone')} /></label>
            <label className="field"><span>Currency code</span><input maxLength={3} placeholder="KES" {...field('currency_code')} /></label>
            <label className="field"><span>Country code</span><input maxLength={2} placeholder="KE" {...field('country_code')} /></label>
            <label className="field settings-field-wide"><span>Address</span><textarea rows={3} maxLength={2000} disabled={!canManage} value={form.address} onChange={(event) => setForm((current) => ({ ...current, address: event.target.value }))} /></label>
          </div>
          {canManage ? (
            <div className="settings-actions">
              <button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save profile'}</button>
            </div>
          ) : (
            <p className="field-hint">You have read-only access to the clinic profile.</p>
          )}
        </form>
      )}
    </div>
  )
}

function EmailSettingsPage({
  data,
  onRefresh,
}: {
  data: EmailSettingsRecord | null
  onRefresh: () => void
}) {
  const [form, setForm] = useState({
    host: '',
    port: '587',
    encryption: 'starttls' as 'starttls' | 'ssl',
    username: '',
    password: '',
    from_address: '',
    from_name: '',
  })
  const [busy, setBusy] = useState(false)
  const [testAddress, setTestAddress] = useState('')
  const [testing, setTesting] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    if (!data) return
    setForm({
      host: data.host ?? '',
      port: String(data.port ?? 587),
      encryption: data.encryption,
      username: data.username ?? '',
      password: '',
      from_address: data.from_address ?? '',
      from_name: data.from_name ?? '',
    })
  }, [data])

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')

    try {
      await apiRequest<EmailSettingsRecord>('/settings/email', {
        method: 'PUT',
        body: JSON.stringify({
          host: form.host,
          port: Number(form.port),
          encryption: form.encryption,
          username: form.username || null,
          password: form.password || null,
          from_address: form.from_address,
          from_name: form.from_name || null,
        }),
      })
      setForm((current) => ({ ...current, password: '' }))
      setNotice('Email settings saved.')
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function sendTest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setTesting(true)
    setError('')
    setNotice('')

    try {
      const result = await apiRequest<{ message: string }>('/settings/email/test', {
        method: 'POST',
        body: JSON.stringify({ email: testAddress }),
      })
      setNotice(result.message)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setTesting(false)
    }
  }

  return (
    <div className="content-stack settings-page">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">ADMINISTRATION</span>
          <h1>Email settings</h1>
          <p className="muted">{moduleDescription('email')}</p>
        </div>
        <div className="welcome-actions">
          <button className="button button-secondary" type="button" onClick={onRefresh}>↻ Refresh</button>
        </div>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      {error && <div className="notice error" role="alert">{error}</div>}
      {!data ? (
        <EmptyState title="Email settings unavailable" message="The mail configuration could not be loaded." />
      ) : (
        <>
          <section className="panel settings-panel">
            <div className="panel-heading">
              <div>
                <h2>Outgoing mail server</h2>
                <p>Used for verification emails, password resets, and customer notifications.</p>
              </div>
              <span className="status-pill status-active">{data.source === 'settings' ? 'Workspace settings' : 'Server environment'}</span>
            </div>
            <form className="settings-form" onSubmit={(event) => void save(event)}>
              <div className="settings-form-grid">
                <label className="field"><span>SMTP host</span><input required maxLength={255} value={form.host} onChange={(event) => setForm((current) => ({ ...current, host: event.target.value }))} /></label>
                <label className="field"><span>Port</span><input required type="number" min={1} max={65535} value={form.port} onChange={(event) => setForm((current) => ({ ...current, port: event.target.value }))} /></label>
                <label className="field"><span>Encryption</span>
                  <select value={form.encryption} onChange={(event) => setForm((current) => ({ ...current, encryption: event.target.value === 'ssl' ? 'ssl' : 'starttls' }))}>
                    <option value="starttls">STARTTLS</option>
                    <option value="ssl">SSL / TLS</option>
                  </select>
                </label>
                <label className="field"><span>Username</span><input maxLength={255} autoComplete="off" value={form.username} onChange={(event) => setForm((current) => ({ ...current, username: event.target.value }))} /></label>
                <label className="field"><span>Password</span><input type="password" maxLength={255} autoComplete="new-password" placeholder={data.password_set ? '•••••••• (leave blank to keep current)' : 'Enter the SMTP password'} value={form.password} onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} /></label>
                <label className="field"><span>From address</span><input required type="email" maxLength={255} value={form.from_address} onChange={(event) => setForm((current) => ({ ...current, from_address: event.target.value }))} /></label>
                <label className="field"><span>From name</span><input maxLength={255} value={form.from_name} onChange={(event) => setForm((current) => ({ ...current, from_name: event.target.value }))} /></label>
              </div>
              <div className="settings-actions">
                <button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save email settings'}</button>
              </div>
            </form>
          </section>

          <section className="panel settings-panel">
            <div className="panel-heading">
              <div>
                <h2>Send a test email</h2>
                <p>Confirm the current settings can deliver mail before you rely on them.</p>
              </div>
            </div>
            <form className="settings-form settings-test-form" onSubmit={(event) => void sendTest(event)}>
              <label className="field"><span>Recipient</span><input required type="email" maxLength={255} value={testAddress} onChange={(event) => setTestAddress(event.target.value)} placeholder={data.from_address ?? 'you@example.com'} /></label>
              <button className="button button-secondary" disabled={testing}>{testing ? 'Sending…' : 'Send test email'}</button>
            </form>
            {data.updated_at && <p className="field-hint">Last updated {formatDate(data.updated_at)}.</p>}
          </section>
        </>
      )}
    </div>
  )
}

function NotificationSettingsPage({
  data,
  onRefresh,
}: {
  data: NotificationSettingsRecord | null
  onRefresh: () => void
}) {
  const [preferences, setPreferences] = useState<Record<string, boolean>>({})
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    if (!data) return
    setPreferences(Object.fromEntries(data.groups.map((group) => [group.key, group.enabled])))
  }, [data])

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')

    try {
      await apiRequest<NotificationSettingsRecord>('/settings/notifications', {
        method: 'PUT',
        body: JSON.stringify({ preferences }),
      })
      setNotice('Notification rules saved.')
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="content-stack settings-page">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">ADMINISTRATION</span>
          <h1>Notification rules</h1>
          <p className="muted">{moduleDescription('notifications')}</p>
        </div>
        <div className="welcome-actions">
          <button className="button button-secondary" type="button" onClick={onRefresh}>↻ Refresh</button>
        </div>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      {error && <div className="notice error" role="alert">{error}</div>}
      {!data ? (
        <EmptyState title="Notification rules unavailable" message="The notification preferences could not be loaded." />
      ) : (
        <section className="panel settings-panel">
          <div className="panel-heading">
            <div>
              <h2>Which emails to send</h2>
              <p>These rules apply to every branch. Transactional messages are sent immediately after the related action succeeds.</p>
            </div>
          </div>
          <form className="settings-form" onSubmit={(event) => void save(event)}>
            <div className="settings-form-grid">
              {data.groups.map((group) => (
                <label className="customer-check" key={group.key}>
                  <input
                    type="checkbox"
                    checked={preferences[group.key] ?? true}
                    onChange={(event) => setPreferences((current) => ({ ...current, [group.key]: event.target.checked }))}
                  />
                  {group.label}
                </label>
              ))}
            </div>
            <p className="field-hint">Turning a group off stops only those emails. The underlying action still completes and remains visible in the workspace.</p>
            <div className="settings-actions">
              <button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save notification rules'}</button>
            </div>
          </form>
          {data.updated_at && <p className="field-hint">Last updated {formatDate(data.updated_at)}.</p>}
        </section>
      )}
    </div>
  )
}

function NotificationBell({
  unreadCount,
  onUnreadCount,
  onOpenInbox,
}: {
  unreadCount: number
  onUnreadCount: (count: number) => void
  onOpenInbox: () => void
}) {
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<InboxNotification[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const wrapperRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handlePointerDown = (event: globalThis.MouseEvent) => {
      if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handlePointerDown)
    return () => document.removeEventListener('mousedown', handlePointerDown)
  }, [])

  useEffect(() => {
    if (!open) return
    let active = true
    setLoading(true)
    setError('')
    void apiRequest<InboxResponse>('/notifications?per_page=8')
      .then((result) => {
        if (!active) return
        setItems(result.data)
        onUnreadCount(result.unread_count)
      })
      .catch((requestError: unknown) => {
        if (active) setError(getErrorMessage(requestError))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [open, onUnreadCount])

  async function openNotification(item: InboxNotification) {
    setOpen(false)
    if (!item.read_at) {
      try {
        const result = await apiRequest<{ unread_count: number }>(`/notifications/${item.id}/read`, { method: 'POST' })
        onUnreadCount(result.unread_count)
      } catch {
        // Navigation should still work when marking as read fails.
      }
    }
    if (item.url?.startsWith('#staff/')) {
      window.location.hash = item.url.slice(1)
    } else {
      onOpenInbox()
    }
  }

  async function markAllRead() {
    try {
      await apiRequest('/notifications/read-all', { method: 'POST' })
      setItems((current) => current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })))
      onUnreadCount(0)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    }
  }

  return (
    <div className="notification-wrap" ref={wrapperRef}>
      <button
        className="notification-button"
        type="button"
        aria-label={unreadCount > 0 ? `${unreadCount} unread notifications` : 'Notifications'}
        title="Notifications"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        ♧
        {unreadCount > 0 && <span className="notification-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>}
      </button>
      {open && (
        <div className="notification-popover" role="dialog" aria-label="Notifications">
          <div className="notification-popover-head">
            <strong>Notifications</strong>
            {unreadCount > 0 && <button className="text-button" type="button" onClick={() => void markAllRead()}>Mark all read</button>}
          </div>
          <div className="notification-list">
            {loading ? <LoadingState compact /> : error ? (
              <p className="notification-empty" role="alert">{error}</p>
            ) : items.length === 0 ? (
              <p className="notification-empty">You have no notifications yet.</p>
            ) : items.map((item) => (
              <button
                className={`notification-item ${item.read_at ? '' : 'unread'}`}
                key={item.id}
                type="button"
                onClick={() => void openNotification(item)}
              >
                <span>{item.group_label}</span>
                <strong>{item.title}</strong>
                {item.message && <span>{item.message}</span>}
                <small>{item.created_at ? formatDate(item.created_at) : ''}</small>
              </button>
            ))}
          </div>
          <div className="notification-popover-foot">
            <button className="text-button" type="button" onClick={() => { setOpen(false); onOpenInbox() }}>View all notifications</button>
          </div>
        </div>
      )}
    </div>
  )
}

function InboxPage({
  loading,
  onRefresh,
  onUnreadChange,
}: {
  loading: boolean
  onRefresh: () => void
  onUnreadChange: (count: number) => void
}) {
  const [folder, setFolder] = useState<'inbox' | 'sent'>('inbox')
  const [category, setCategory] = useState('')
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [items, setItems] = useState<InboxNotification[]>([])
  const [categories, setCategories] = useState<{ key: string; label: string }[]>([])
  const [meta, setMeta] = useState<MailboxResponse['meta'] | null>(null)
  const [pageNumber, setPageNumber] = useState(1)
  const [unread, setUnread] = useState(0)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [reply, setReply] = useState('')
  const [composeOpen, setComposeOpen] = useState(false)
  const [refreshKey, setRefreshKey] = useState(0)

  useEffect(() => {
    let active = true
    setBusy(true)
    setError('')
    const query = new URLSearchParams({ folder, per_page: '25', page: String(pageNumber) })
    if (category) query.set('category', category)
    if (unreadOnly) query.set('filter', 'unread')
    void apiRequest<MailboxResponse>(`/mailbox?${query.toString()}`)
      .then((result) => {
        if (!active) return
        setItems(result.data)
        setCategories(result.categories)
        setMeta(result.meta)
        setUnread(result.unread_count)
        onUnreadChange(result.unread_count)
        setSelectedId((current) => result.data.some((item) => item.id === current) ? current : (result.data[0]?.id ?? null))
      })
      .catch((requestError: unknown) => {
        if (active) setError(getErrorMessage(requestError))
      })
      .finally(() => {
        if (active) setBusy(false)
      })
    return () => { active = false }
  }, [folder, category, unreadOnly, pageNumber, refreshKey])

  const selected = items.find((item) => item.id === selectedId) ?? null

  async function markRead(item: InboxNotification) {
    if (item.read_at || item.folder === 'sent') return
    try {
      const result = await apiRequest<{ unread_count: number }>(`/notifications/${item.id}/read`, { method: 'POST' })
      setItems((current) => current.map((entry) => entry.id === item.id ? { ...entry, read_at: new Date().toISOString() } : entry))
      setUnread(result.unread_count)
      onUnreadChange(result.unread_count)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    }
  }

  function selectMessage(item: InboxNotification) {
    setSelectedId(item.id)
    setReply('')
    setNotice('')
    if (!item.read_at && item.folder !== 'sent') void markRead(item)
  }

  async function markAllRead() {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await apiRequest('/notifications/read-all', { method: 'POST' })
      setItems((current) => current.map((item) => item.folder === 'sent' ? item : { ...item, read_at: item.read_at ?? new Date().toISOString() }))
      setUnread(0)
      onUnreadChange(0)
      setNotice('All messages marked as read.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function sendReply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selected) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<{ delivered: boolean; unread_count: number }>(`/mailbox/reply/${selected.id}`, {
        method: 'POST',
        body: JSON.stringify({ message: reply }),
      })
      setItems((current) => current.map((item) => item.id === selected.id ? { ...item, read_at: new Date().toISOString() } : item))
      setUnread(result.unread_count)
      onUnreadChange(result.unread_count)
      setReply('')
      setNotice(result.delivered ? 'Reply sent by email.' : 'Reply saved, but the email provider did not confirm delivery. Check Email settings.')
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  async function sendCompose(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const result = await apiRequest<{ delivered: boolean }>('/mailbox/messages', {
        method: 'POST',
        body: JSON.stringify({
          to: String(form.get('to') ?? ''),
          category: String(form.get('category') ?? ''),
          subject: String(form.get('subject') ?? ''),
          message: String(form.get('message') ?? ''),
        }),
      })
      setComposeOpen(false)
      setNotice(result.delivered ? 'Email sent and saved to your Sent folder.' : 'Email saved to Sent, but delivery was not confirmed. Check Email settings.')
      if (folder === 'sent') setRefreshKey((current) => current + 1)
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="content-stack">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">YOUR MAILBOX</span>
          <h1>Mailbox</h1>
          <p className="muted">{moduleDescription('inbox')}</p>
        </div>
        <div className="dialog-actions">
          <button className="button button-primary" type="button" onClick={() => setComposeOpen(true)}>Compose</button>
          <button className="button button-secondary" type="button" disabled={busy || unread === 0} onClick={() => void markAllRead()}>Mark all read</button>
          <button className="button button-secondary" type="button" onClick={() => { setNotice(''); setError(''); setRefreshKey((current) => current + 1); onRefresh() }}>↻ Refresh</button>
        </div>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      {error && <div className="notice error" role="alert">{error}</div>}
      <div className="mailbox-toolbar">
        <div className="dialog-actions">
          <button className={`button ${folder === 'inbox' ? 'button-primary' : 'button-secondary'}`} type="button" onClick={() => { setFolder('inbox'); setPageNumber(1) }}>Inbox{unread > 0 ? ` (${unread})` : ''}</button>
          <button className={`button ${folder === 'sent' ? 'button-primary' : 'button-secondary'}`} type="button" onClick={() => { setFolder('sent'); setPageNumber(1) }}>Sent</button>
          {folder === 'inbox' && <button className={`button ${unreadOnly ? 'button-primary' : 'button-secondary'}`} type="button" onClick={() => { setUnreadOnly((current) => !current); setPageNumber(1) }}>Unread only</button>}
        </div>
        <div className="mailbox-category-bar" role="group" aria-label="Message categories">
          <button className={`mailbox-category ${category === '' ? 'active' : ''}`} type="button" onClick={() => { setCategory(''); setPageNumber(1) }}>All categories</button>
          {categories.map((entry) => (
            <button key={entry.key} className={`mailbox-category ${category === entry.key ? 'active' : ''}`} type="button" onClick={() => { setCategory(entry.key); setPageNumber(1) }}>{entry.label}</button>
          ))}
        </div>
      </div>
      {loading && !items.length ? <LoadingState /> : !items.length ? (
        <EmptyState
          title={folder === 'sent' ? 'No sent messages yet' : unreadOnly ? 'You are all caught up' : 'No messages yet'}
          message={folder === 'sent'
            ? 'Replies and composed emails you send will be kept here.'
            : 'Updates about appointments, orders, payments, and team actions will appear here.'}
        />
      ) : (
        <div className="mailbox-layout">
          <section className="panel mailbox-list" aria-label={folder === 'sent' ? 'Sent messages' : 'Inbox messages'}>
            {items.map((item) => (
              <button
                key={item.id}
                type="button"
                className={`mailbox-item ${item.id === selectedId ? 'selected' : ''} ${item.read_at ? '' : 'unread'}`}
                onClick={() => selectMessage(item)}
              >
                <span className="eyebrow">{item.group_label}</span>
                <strong>{item.title}</strong>
                <p>{item.message}</p>
                <small>
                  {folder === 'sent'
                    ? `To ${item.recipient_name || item.recipient_email || 'recipient'}`
                    : item.reply_to ? `Reply to ${item.reply_to_name || item.reply_to}` : ''}
                  {item.created_at ? ` · ${formatDate(item.created_at)}` : ''}
                </small>
              </button>
            ))}
            {meta && meta.last_page > 1 && (
              <div className="mailbox-pagination">
                <button className="button button-secondary" type="button" disabled={meta.current_page <= 1} onClick={() => setPageNumber((current) => Math.max(1, current - 1))}>Previous</button>
                <span>Page {meta.current_page} of {meta.last_page}</span>
                <button className="button button-secondary" type="button" disabled={meta.current_page >= meta.last_page} onClick={() => setPageNumber((current) => current + 1)}>Next</button>
              </div>
            )}
          </section>
          <section className="panel mailbox-detail" aria-live="polite">
            {!selected ? (
              <EmptyState title="Select a message" message="Choose a message to read it and reply." />
            ) : (
              <>
                <span className="eyebrow">{selected.group_label} · {selected.folder === 'sent' ? 'Sent' : 'Inbox'}</span>
                <h2>{selected.title}</h2>
                <p className="muted mailbox-detail-meta">
                  {selected.folder === 'sent'
                    ? `To ${selected.recipient_name || selected.recipient_email || 'recipient'}${selected.status === 'failed' ? ' · Delivery failed' : ' · Delivered'}`
                    : selected.reply_to ? `From ${selected.reply_to_name || selected.reply_to}` : 'Clinic update'}
                  {selected.created_at ? ` · ${formatDate(selected.created_at)}` : ''}
                </p>
                <p className="mailbox-detail-body">{selected.message}</p>
                {selected.folder !== 'sent' && selected.url?.startsWith('#staff/') && (
                  <button className="button button-secondary" type="button" onClick={() => { window.location.hash = selected.url!.slice(1) }}>Open related record</button>
                )}
                {selected.folder !== 'sent' && selected.reply_to && (
                  <form className="form-stack mailbox-reply" onSubmit={(event) => void sendReply(event)}>
                    <label>Reply to {selected.reply_to_name || selected.reply_to}<textarea name="reply" required rows={4} maxLength={5000} value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Write your reply. It is emailed to the customer and saved in Sent." /></label>
                    <div className="dialog-actions">
                      <button className="button button-primary" type="submit" disabled={busy || !reply.trim()}>{busy ? 'Sending…' : 'Send reply'}</button>
                    </div>
                  </form>
                )}
              </>
            )}
          </section>
        </div>
      )}
      {composeOpen && (
        <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setComposeOpen(false) }}>
          <section className="dialog" role="dialog" aria-modal="true" aria-labelledby="compose-title">
            <button className="dialog-close" type="button" onClick={() => setComposeOpen(false)} aria-label="Close compose window">×</button>
            <span className="eyebrow">NEW EMAIL</span>
            <h2 id="compose-title">Compose email</h2>
            <p className="muted">Emails are sent from the clinic address and saved to your Sent folder.</p>
            <form className="form-stack" onSubmit={(event) => void sendCompose(event)}>
              <label>To<input name="to" type="email" required maxLength={255} placeholder="customer@example.com" /></label>
              <label>Category<select name="category" defaultValue="customer_account">{categories.map((entry) => <option key={entry.key} value={entry.key}>{entry.label}</option>)}</select></label>
              <label>Subject<input name="subject" required maxLength={160} /></label>
              <label>Message<textarea name="message" required rows={6} maxLength={5000} /></label>
              <button className="button button-primary button-wide" type="submit" disabled={busy}>{busy ? 'Sending…' : 'Send email'}</button>
            </form>
          </section>
        </div>
      )}
    </div>
  )
}
function AccountSecurityPage({
  user,
  sessions,
  onSignOut,
  onRefresh,
}: {
  user: AuthUser
  sessions: AuthSessionRecord[] | null
  onSignOut: () => void
  onRefresh: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [revoking, setRevoking] = useState<number | null>(null)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  async function changePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    const form = new FormData(event.currentTarget)

    try {
      await apiRequest('/auth/password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: form.get('current_password'),
          password: form.get('password'),
          password_confirmation: form.get('password_confirmation'),
        }),
      })
      onSignOut()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
      setBusy(false)
    }
  }

  async function revokeSession(session: AuthSessionRecord) {
    setRevoking(session.id)
    setError('')
    setNotice('')

    try {
      await apiRequest(`/auth/sessions/${session.id}`, { method: 'DELETE' })
      setNotice('Session revoked.')
      onRefresh()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setRevoking(null)
    }
  }

  return (
    <div className="content-stack settings-page">
      <div className="welcome-row">
        <div>
          <span className="eyebrow">YOUR ACCOUNT</span>
          <h1>Account & security</h1>
          <p className="muted">{moduleDescription('account')}</p>
        </div>
        <div className="welcome-actions">
          <button className="button button-secondary" type="button" onClick={onRefresh}>↻ Refresh</button>
        </div>
      </div>
      {notice && <div className="notice success" role="status">{notice}</div>}
      {error && <div className="notice error" role="alert">{error}</div>}

      <section className="panel settings-panel">
        <div className="panel-heading">
          <div>
            <h2>Your profile</h2>
            <p>Contact an administrator if your name or email needs to change.</p>
          </div>
        </div>
        <div className="account-summary">
          <span className="profile-avatar">{initials(user.name)}</span>
          <span><strong>{user.name}</strong><small>{user.email}</small></span>
          <span className="role-chip">{user.roles[0] ?? 'Team member'}</span>
        </div>
      </section>

      <section className="panel settings-panel">
        <div className="panel-heading">
          <div>
            <h2>Change password</h2>
            <p>At least 12 characters with upper and lower case, a number, and a symbol.</p>
          </div>
        </div>
        <form className="settings-form" onSubmit={(event) => void changePassword(event)}>
          <div className="settings-form-grid">
            <PasswordField name="current_password" label="Current password" autoComplete="current-password" />
            <PasswordField name="password" label="New password" autoComplete="new-password" />
            <PasswordField name="password_confirmation" label="Confirm new password" autoComplete="new-password" />
          </div>
          <div className="settings-actions">
            <button className="button button-primary" disabled={busy}>{busy ? 'Updating…' : 'Update password'}</button>
          </div>
        </form>
      </section>

      <section className="panel settings-panel">
        <div className="panel-heading">
          <div>
            <h2>Active sessions</h2>
            <p>Devices currently signed in with your account.</p>
          </div>
        </div>
        {!sessions ? <LoadingState compact /> : sessions.length === 0 ? (
          <EmptyState title="No active sessions" message="Sign in again to create a session." />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>DEVICE</th><th>SIGNED IN</th><th>LAST USED</th><th>STATUS</th><th /></tr></thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.id}>
                    <td><span className="table-entity"><span className="table-icon sage">▣</span><strong>{session.name || 'Clinic workspace'}</strong></span></td>
                    <td>{formatDate(session.created_at)}</td>
                    <td>{session.last_used_at ? formatDate(session.last_used_at) : 'Not yet used'}</td>
                    <td>{session.current ? <span className="status-pill status-active"><i />Current</span> : <span className="status-pill status-inactive"><i />Signed in</span>}</td>
                    <td>
                      <button className="text-button" type="button" disabled={session.current || revoking === session.id} onClick={() => void revokeSession(session)}>
                        {revoking === session.id ? 'Revoking…' : 'Revoke'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}

function ModulePage({
  page,
  data,
  loading,
  branchScope,
  activeBranchName,
  onRefresh,
}: {
  page: PageKey
  data: unknown
  loading: boolean
  branchScope: string
  activeBranchName: string
  onRefresh: () => void
}) {
  const [search, setSearch] = useState('')
  const title = pageTitle(page)

  return (
    <div className="content-stack">
      <div className="welcome-row module-title-row">
        <div><span className="eyebrow">OSHEL WORKSPACE</span><h1>{title}</h1><p className="muted">{moduleDescription(page)}</p></div>
        <div className="module-actions">
          <button className="button button-secondary" type="button" onClick={onRefresh}>↻ <span className="hide-small">Refresh</span></button>
        </div>
      </div>
      <section className="panel table-panel">
        <div className="table-toolbar">
          <div className="table-scope"><span className="scope-dot" />{branchScope === 'all' ? 'All permitted branches' : activeBranchName}</div>
          <label className="search-field">
            <span aria-hidden="true">⌕</span>
            <span className="sr-only">Search {title.toLowerCase()}</span>
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={`Search ${title.toLowerCase()}…`} />
          </label>
        </div>
        {loading ? <LoadingState compact /> : renderModuleTable(page, data, search)}
      </section>
    </div>
  )
}

function renderModuleTable(page: PageKey, data: unknown, search: string) {
  if (page === 'audit') {
    const events = (data as Paginated<AuditEvent> | null)?.data ?? []
    const filtered = events.filter((event) => includesSearch([
      event.action, event.subject_type, event.subject_id, event.user?.name, event.branch?.name,
    ], search))
    return filtered.length ? (
      <div className="table-scroll"><table><thead><tr><th>ACTIVITY</th><th>ACTOR</th><th>BRANCH</th><th>RESULT</th><th>WHEN</th></tr></thead>
        <tbody>{filtered.map((event) => <tr key={event.id}>
          <td><span className="audit-action"><strong>{formatAction(event.action)}</strong><small>{event.subject_type ? `${event.subject_type.split('\\').pop()} · ${event.subject_id ?? ''}` : 'Workspace event'}</small></span></td>
          <td>{event.user?.name ?? 'System'}</td><td>{event.branch?.name ?? '—'}</td>
          <td><span className={`status-pill ${event.status_code && event.status_code < 400 ? 'status-active' : 'status-inactive'}`}><i />{event.status_code ?? '—'}</span></td>
          <td>{formatDate(event.occurred_at)}</td>
        </tr>)}</tbody></table></div>
    ) : <EmptyState title="No activity found" message={search ? 'Try another search term.' : 'Audited activity will appear here as your team works.'} />
  }
  return <EmptyState title="No data available" message="This module is not available for your account." />
}

function AccountDialog({
  user,
  onClose,
  onLogout,
  onPasswordChanged,
}: {
  user: AuthUser
  onClose: () => void
  onLogout: () => void
  onPasswordChanged: () => void
}) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const dialogRef = useRef<HTMLElement>(null)

  useEffect(() => {
    dialogRef.current?.focus()
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [onClose])

  async function changePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      await apiRequest('/auth/password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: form.get('current_password'),
          password: form.get('password'),
          password_confirmation: form.get('password_confirmation'),
        }),
      })
      onPasswordChanged()
    } catch (requestError) {
      setError(getErrorMessage(requestError))
      setBusy(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose()
    }}>
      <section ref={dialogRef} className="dialog" role="dialog" aria-modal="true" aria-labelledby="account-title" tabIndex={-1}>
        <div className="dialog-heading"><div><span className="eyebrow">YOUR ACCOUNT</span><h2 id="account-title">Account settings</h2></div><button className="button-icon-only" type="button" aria-label="Close" onClick={onClose}>×</button></div>
        <div className="account-summary"><span className="profile-avatar">{initials(user.name)}</span><span><strong>{user.name}</strong><small>{user.email}</small></span></div>
        <form className="form-stack dialog-form" onSubmit={changePassword}>
          <h3>Change password</h3>
          {error && <div className="notice error" role="alert">{error}</div>}
          <PasswordField name="current_password" label="Current password" autoComplete="current-password" />
          <PasswordField name="password" label="New password" autoComplete="new-password" />
          <PasswordField name="password_confirmation" label="Confirm new password" autoComplete="new-password" />
          <button className="button button-primary button-wide" disabled={busy}>{busy ? 'Updating…' : 'Update password'}</button>
        </form>
        <div className="dialog-footer"><button className="button button-danger" type="button" onClick={onLogout}>Sign out</button><button className="button button-secondary" type="button" onClick={onClose}>Done</button></div>
      </section>
    </div>
  )
}

function MetricCard({
  label,
  value,
  icon,
  tone,
  note,
}: {
  label: string
  value: number
  icon: string
  tone: string
  note: string
}) {
  return (
    <article className="metric-card">
      <div className={`metric-icon ${tone}`}>{icon}</div>
      <span className="metric-label">{label}</span>
      <strong className="metric-value">{value.toLocaleString()}</strong>
      <span className="metric-note">{note}</span>
    </article>
  )
}

function StatusPill({ active }: { active: boolean }) {
  return <span className={`status-pill ${active ? 'status-active' : 'status-inactive'}`}><i />{active ? 'Active' : 'Inactive'}</span>
}

function EmptyState({
  title,
  message,
  compact = false,
}: {
  title: string
  message: string
  compact?: boolean
}) {
  return (
    <div className={`empty-state ${compact ? 'empty-compact' : ''}`}>
      <span className="empty-icon" aria-hidden="true">✳</span>
      <strong>{title}</strong>
      <p>{message}</p>
    </div>
  )
}

function LoadingState({ compact = false }: { compact?: boolean }) {
  return (
    <div className={`loading-state ${compact ? 'loading-compact' : ''}`} role="status" aria-live="polite">
      <span className="spinner" />
      <span>Loading your workspace…</span>
    </div>
  )
}

function getErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message
  }
  return error instanceof Error ? error.message : 'Something went wrong. Please try again.'
}

function usePaginatedList<T>(items: T[], pageSize = 10) {
  const [currentPage, setCurrentPage] = useState(1)
  const totalPages = Math.max(1, Math.ceil(items.length / pageSize))
  useEffect(() => {
    if (currentPage > totalPages) setCurrentPage(totalPages)
  }, [currentPage, totalPages])
  const activePage = Math.min(currentPage, totalPages)
  const pageItems = useMemo(
    () => items.slice((activePage - 1) * pageSize, activePage * pageSize),
    [items, activePage, pageSize],
  )
  return {
    pageItems,
    currentPage: activePage,
    totalPages,
    setCurrentPage,
    pageSize,
    total: items.length,
  }
}

function Pagination({
  page,
  totalPages,
  total,
  pageSize,
  onPage,
  label = 'Pagination',
}: {
  page: number
  totalPages: number
  total: number
  pageSize: number
  onPage: (page: number) => void
  label?: string
}) {
  if (total === 0) return null
  const from = (page - 1) * pageSize + 1
  const to = Math.min(page * pageSize, total)
  return (
    <div className="pagination" role="navigation" aria-label={label}>
      <span className="pagination-summary">Showing {from}–{to} of {total}</span>
      <div className="pagination-controls">
        <button className="button button-secondary pagination-button" type="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>Previous</button>
        <span className="pagination-page">Page {page} of {totalPages}</span>
        <button className="button button-secondary pagination-button" type="button" disabled={page >= totalPages} onClick={() => onPage(page + 1)}>Next</button>
      </div>
    </div>
  )
}

function includesSearch(values: (string | null | undefined)[], search: string): boolean {
  const normalized = search.trim().toLowerCase()
  return !normalized || values.some((value) => value?.toLowerCase().includes(normalized))
}

function initials(name: string): string {
  return name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '').join('')
}

function pageTitle(page: PageKey): string {
  return ({
    overview: 'Overview',
    inbox: 'Mailbox',
    analytics: 'Analytics & reports',
    branches: 'Branches',
    departments: 'Departments',
    users: 'Staff',
    roles: 'Roles & access',
    audit: 'Audit activity',
    customers: 'Customers',
    services: 'Services',
    appointments: 'Appointments',
    clinical: 'Clinical care',
    hr: 'People & HR',
    payroll: 'Payroll',
    inventory: 'Inventory',
    procurement: 'Procurement',
    pos: 'Point of sale',
    'pos-management': 'POS management',
    ecommerce: 'Online orders',
    payments: 'Payments & reconciliation',
    accounting: 'Accounting',
    website: 'Website content',
    growth: 'Website growth',
    marketing: 'Marketing centre',
    crm: 'CRM & marketing',
    organization: 'Clinic profile',
    email: 'Email settings',
    notifications: 'Notification rules',
    account: 'Account & security',
  })[page]
}

function moduleDescription(page: PageKey): string {
  return ({
    overview: 'A thoughtful snapshot of your clinic.',
    inbox: 'Read, reply to, and send emails for your role, branches, and customers.',
    analytics: 'Explore permission-filtered business performance with clear definitions, sources, date basis, and audited exports.',
    branches: 'Explore the locations in your organization.',
    departments: 'See the teams and services across your branches.',
    users: 'Review the people who make your clinic special.',
    roles: 'Understand the access assigned across your team.',
    audit: 'A clear record of important workspace activity.',
    customers: 'Manage customer identity and linked patient profiles.',
    services: 'Manage the treatments, pricing, and booking availability offered by your clinic.',
    appointments: 'See booking flow, queue status, and service delivery across branches.',
    clinical: 'Document clinical encounters and coordinate treatment follow-ups.',
    hr: 'Manage employee profiles, contracts, shifts, attendance, and leave.',
    payroll: 'Configure compensation, review payroll runs, and prepare accounting exports.',
    inventory: 'Manage products, batch-aware branch stock, reorder alerts, and inventory valuation.',
    procurement: 'Process purchase requests, supplier orders, goods receipts, and invoice matching.',
    ecommerce: 'Review online customer orders, reserved stock, fulfilment state, and return requests.',
    payments: 'Track provider-confirmed payments and reconcile settlements to clearing accounts.',
    accounting: 'Configure the chart of accounts, source posting rules, journals, periods, and financial reports.',
    crm: 'Manage enquiries, consent-based customer segments, marketing campaigns, team tasks, budgets, and attribution.',
    pos: 'Sell clinic services and in-stock products, reconcile tills, and print customer receipts.',
    'pos-management': 'Monitor shifts, staff sales, refunds, registers, POS settings, and staff PIN access.',
    website: 'Manage public page copy, publication status, schedules, and SEO metadata.',
    growth: 'Manage loyalty rules, verified reviews, featured products, promotions, and social links.',
    marketing: 'Connect marketing platforms, coordinate social content, review campaign snapshots, and improve branch SEO.',
    organization: 'Manage the clinic identity, contact details, and regional settings used across the workspace.',
    email: 'Review and update the outgoing mail server used for notifications and password resets.',
    notifications: 'Choose which customer and team emails the workspace sends after key actions.',
    account: 'Manage your password and review the devices signed in to your account.',
  })[page]
}

function formatAction(action: string): string {
  return action.replace(/^[a-z]+:/, '').replace(/[._]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

function nextOrderStatusActions(order: CustomerActivityOrder): { status: string; label: string }[] {
  if (order.status === 'confirmed') return [{ status: 'processing', label: 'Start processing' }]
  if (order.status === 'processing') {
    return order.fulfillment_type === 'collection'
      ? [{ status: 'ready_for_collection', label: 'Ready for collection' }]
      : [{ status: 'dispatched', label: 'Mark dispatched' }]
  }
  if (order.status === 'ready_for_collection' || order.status === 'dispatched') return [{ status: 'completed', label: 'Complete order' }]
  if (order.status === 'awaiting_payment') return [{ status: 'cancelled', label: 'Cancel order' }]
  return []
}

function formatMoneyValue(value: string | number | null | undefined, currencyCode = 'KES'): string {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  return `${currencyCode} ${amount.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatAppointmentTime(value: string, includeDate = false): string {
  return new Intl.DateTimeFormat(undefined, {
    ...(includeDate ? { month: 'short', day: 'numeric' } : {}),
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

export default App
