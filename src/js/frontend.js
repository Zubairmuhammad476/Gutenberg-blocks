/**
 * frontend.js — MFD Dashboard Widget (Phase 2: DOMPurify XSS Hardening)
 *
 * React 18 hydration runtime. Mounts onto the PHP skeleton shell produced
 * by templates/render.php. Fetches live metrics from /custom-dashboard/v1/
 * user-metrics with nonce authentication and exponential-backoff retry.
 *
 * XSS Security contract:
 *   Every string originating from the REST API that is injected into the DOM
 *   is passed through DOMPurify.sanitize() before use. This covers:
 *     - display_name, email, formatted_login
 *     - activity_log event types and all payload key/value strings
 *     - download file names, device names, and any arbitrary metadata
 *   DOMPurify strips all script tags, event handlers, and injection vectors.
 *
 * @see https://github.com/cure53/DOMPurify
 */

import { createElement, useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import DOMPurify from 'dompurify';
import '../css/style.css';

// ─────────────────────────────────────────────────────────────────────────────
// DOMPurify configuration — strict, text-only sanitization for all user data.
// ALLOWED_TAGS: [] means all HTML tags are stripped → output is plain text.
// ALLOWED_ATTR: [] strips all attributes as well.
// ─────────────────────────────────────────────────────────────────────────────
const PURIFY_TEXT_ONLY = { ALLOWED_TAGS: [], ALLOWED_ATTR: [], RETURN_DOM: false };

/**
 * Sanitize any value to a safe, XSS-free plain text string.
 * Accepts strings, numbers, booleans, null. Arrays and objects are
 * serialized to JSON-string first, then sanitized.
 *
 * @param {*} value — Arbitrary value from REST API response.
 * @returns {string} Safe plain-text string.
 */
function purify( value ) {
    if ( value === null || value === undefined ) return '';
    if ( typeof value === 'number' || typeof value === 'boolean' ) return String( value );
    if ( typeof value === 'string' ) return DOMPurify.sanitize( value, PURIFY_TEXT_ONLY );
    // Arrays / objects: flatten to JSON text, then strip any tags.
    return DOMPurify.sanitize( JSON.stringify( value ), PURIFY_TEXT_ONLY );
}

/**
 * Sanitize all string leaves in a plain object recursively.
 * Used to clean activity_log payload objects before rendering.
 *
 * @param {Object} obj — Raw API object.
 * @returns {Object} Sanitized copy.
 */
function purifyObject( obj ) {
    if ( ! obj || typeof obj !== 'object' ) return {};
    return Object.fromEntries(
        Object.entries( obj ).map( ( [ k, v ] ) => [
            purify( k ),
            typeof v === 'object' ? purifyObject( v ) : purify( v ),
        ] )
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────────
const MAX_RETRIES       = 3;
const RETRY_BASE_MS     = 800;  // ms — doubles each attempt: 800, 1600, 3200
const ACTIVITY_PER_PAGE = 10;

// ─────────────────────────────────────────────────────────────────────────────
// Hydration entry point
// ─────────────────────────────────────────────────────────────────────────────

function initAllBlocks() {
    const config = window.mfdDashboard;

    if ( ! config?.restUrl || ! config?.nonce ) {
        // Not logged in or localization failed — leave the skeleton in place.
        return;
    }

    document.querySelectorAll( '.mfd-dashboard-block' ).forEach( ( blockEl ) => {
        let widgetConfig = {};
        try {
            widgetConfig = JSON.parse( blockEl.dataset.widgetConfig || '{}' );
        } catch {
            // Malformed JSON — use attribute defaults.
        }

        // Destroy the PHP skeleton before mounting React so there is no
        // duplicate content during the transition frame.
        const skeletonEl = blockEl.querySelector( '[data-mfd-skeleton]' );
        if ( skeletonEl ) skeletonEl.setAttribute( 'aria-hidden', 'true' );

        createRoot( blockEl ).render(
            createElement( DashboardWidget, {
                config,
                widgetConfig,
                instanceId: purify( blockEl.dataset.instanceId || 'mfd-0' ),
            } )
        );

        // Mark region as no longer busy once React takes over.
        blockEl.removeAttribute( 'aria-busy' );
    } );
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initAllBlocks );
} else {
    initAllBlocks();
}

// ─────────────────────────────────────────────────────────────────────────────
// useFetch — generic hook with exponential backoff retry.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Custom hook that fetches a URL with nonce auth and retries on failure.
 *
 * @param {string}   url        Full REST API URL to fetch.
 * @param {Object}   config     window.mfdDashboard config object.
 * @param {boolean}  [enabled]  Set to false to skip fetching.
 * @returns {{ status, data, error, retry }}
 */
function useFetch( url, config, enabled = true ) {
    const [ status, setStatus ] = useState( 'idle' );   // idle | loading | success | error
    const [ data,   setData   ] = useState( null );
    const [ error,  setError  ] = useState( null );
    const retries = useRef( 0 );
    const abortRef = useRef( null );

    const execute = useCallback( async () => {
        if ( ! enabled ) return;

        setStatus( 'loading' );
        setError( null );

        // Abort any in-flight request before firing a new one.
        if ( abortRef.current ) abortRef.current.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        try {
            const response = await fetch( url, {
                method:      'GET',
                credentials: 'same-origin',
                signal:      controller.signal,
                headers: {
                    'Content-Type':                          'application/json',
                    [ config.nonceHeader || 'X-MFD-Nonce' ]: config.nonce,
                    'X-Requested-With':                      'XMLHttpRequest',
                },
            } );

            if ( ! response.ok ) {
                const errJson = await response.json().catch( () => ( {} ) );
                throw new Error( purify( errJson.message ) || `HTTP ${ response.status }` );
            }

            const json = await response.json();

            if ( ! json.success ) {
                throw new Error( purify( json.message ) || 'API returned success: false' );
            }

            // Refresh nonce from response if server rotated it.
            if ( json.data?.nonce ) config.nonce = json.data.nonce;

            retries.current = 0;
            setData( json.data );
            setStatus( 'success' );
        } catch ( err ) {
            if ( err.name === 'AbortError' ) return; // Intentional — ignore.

            if ( retries.current < MAX_RETRIES ) {
                const delay = RETRY_BASE_MS * Math.pow( 2, retries.current );
                retries.current++;
                setTimeout( execute, delay );
            } else {
                setError( purify( err.message ) || 'Unknown error.' );
                setStatus( 'error' );
            }
        }
    }, [ url, config, enabled ] );

    useEffect( () => {
        if ( enabled ) {
            retries.current = 0;
            execute();
        }
        return () => abortRef.current?.abort();
    }, [ execute, enabled ] );

    return { status, data, error, retry: execute };
}

// ─────────────────────────────────────────────────────────────────────────────
// DashboardWidget — root component
// ─────────────────────────────────────────────────────────────────────────────

function DashboardWidget( { config, widgetConfig, instanceId } ) {
    const i18n = config.i18n || {};

    const metricsUrl = config.restUrl;
    const { status, data, error, retry } = useFetch( metricsUrl, config );

    // Activity state — fetched separately after metrics succeed.
    const [ actPage,   setActPage   ] = useState( 1 );
    const [ actData,   setActData   ] = useState( { items: [], total: 0, pages: 1, current_page: 1 } );
    const [ actStatus, setActStatus ] = useState( 'idle' );
    const actAbort = useRef( null );

    const fetchActivity = useCallback( async ( page ) => {
        setActStatus( 'loading' );
        if ( actAbort.current ) actAbort.current.abort();
        const ctrl = new AbortController();
        actAbort.current = ctrl;

        try {
            const url = new URL( metricsUrl + '/activity' );
            url.searchParams.set( 'page',     String( page ) );
            url.searchParams.set( 'per_page', String( ACTIVITY_PER_PAGE ) );

            const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                signal:      ctrl.signal,
                headers: {
                    'Content-Type':                          'application/json',
                    [ config.nonceHeader || 'X-MFD-Nonce' ]: config.nonce,
                    'X-Requested-With':                      'XMLHttpRequest',
                },
            } );

            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            const json = await res.json();
            if ( ! json.success ) throw new Error( 'Activity API error.' );

            setActData( json.data );
            setActPage( page );
            setActStatus( 'success' );
        } catch ( e ) {
            if ( e.name === 'AbortError' ) return;
            setActStatus( 'error' );
        }
    }, [ metricsUrl, config ] );

    useEffect( () => {
        if ( status === 'success' && widgetConfig.showActivity ) {
            fetchActivity( 1 );
        }
        return () => actAbort.current?.abort();
    }, [ status ] ); // eslint-disable-line react-hooks/exhaustive-deps

    if ( status === 'loading' || status === 'idle' ) {
        // PHP skeleton is visible — return null to avoid double-render flash.
        return null;
    }

    if ( status === 'error' ) {
        return createElement( ErrorState, {
            title:      i18n.errorTitle  || 'Unable to load metrics',
            message:    error,
            retryLabel: i18n.errorRetry  || 'Retry',
            onRetry:    retry,
        } );
    }

    const accent = widgetConfig.accentColor || '#6366f1';

    return createElement( 'div', { className: 'mfd-dashboard', id: `${ instanceId }-hydrated` },
        createElement( ProfileCard,    { data, i18n } ),
        createElement( MetricCardRow,  { data, widgetConfig, accent, i18n } ),
        widgetConfig.showStrength  && createElement( StrengthMeter, { score: data.profile_strength ?? 0, accent, i18n } ),
        widgetConfig.showActivity  && createElement( ActivityGrid,  {
            activity:   actData,
            status:     actStatus,
            page:       actPage,
            onPage:     fetchActivity,
            accent,
            i18n,
        } )
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ProfileCard
// ─────────────────────────────────────────────────────────────────────────────

function ProfileCard( { data, i18n } ) {
    // All user strings are DOMPurify-sanitized before rendering.
    const name      = purify( data.display_name  || '' );
    const email     = purify( data.email         || '' );
    const avatarUrl = purify( data.avatar_url    || '' );

    return createElement( 'div', { className: 'mfd-profile-card' },
        createElement( 'img', {
            src:       avatarUrl,
            alt:       name,
            className: 'mfd-profile-avatar',
            width:     64,
            height:    64,
            loading:   'lazy',
        } ),
        createElement( 'div', null,
            createElement( 'div', { className: 'mfd-profile-name'  }, name  ),
            createElement( 'div', { className: 'mfd-profile-email' }, email )
        )
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// MetricCardRow
// ─────────────────────────────────────────────────────────────────────────────

function MetricCardRow( { data, widgetConfig, accent, i18n } ) {
    const loginVal = purify( data.formatted_login || ( i18n.never || 'Never' ) );

    return createElement( 'div', { className: 'mfd-metric-grid' },
        widgetConfig.showLastLogin && createElement( MetricCard, {
            label:  i18n.lastLogin  || 'Last Login',
            value:  loginVal,
            sub:    'UTC',
            accent,
        } ),
        widgetConfig.showDownloads && createElement( MetricCard, {
            label:  i18n.downloads  || 'Downloads',
            value:  purify( String( data.download_count ?? 0 ) ),
            sub:    'Total',
            accent,
        } ),
        widgetConfig.showStrength  && createElement( MetricCard, {
            label:  i18n.strength   || 'Profile Strength',
            value:  `${ data.profile_strength ?? 0 }%`,
            sub:    strengthLabel( data.profile_strength ),
            accent,
        } )
    );
}

function MetricCard( { label, value, sub, accent } ) {
    return createElement( 'div', { className: 'mfd-metric-card' },
        createElement( 'span', { className: 'mfd-metric-card__label' }, purify( label ) ),
        createElement( 'span', { className: 'mfd-metric-card__value', style: { color: accent } }, value ),
        sub && createElement( 'span', { className: 'mfd-metric-card__sub' }, purify( sub ) )
    );
}

function strengthLabel( score ) {
    if ( score >= 80 ) return 'Excellent';
    if ( score >= 60 ) return 'Good';
    if ( score >= 40 ) return 'Fair';
    return 'Needs work';
}

// ─────────────────────────────────────────────────────────────────────────────
// StrengthMeter — SVG circular progress with animated strokeDashoffset
// ─────────────────────────────────────────────────────────────────────────────

const RING_RADIUS        = 40;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS; // ≈ 251.33

function StrengthMeter( { score, accent, i18n } ) {
    const clamped = Math.max( 0, Math.min( 100, score ?? 0 ) );
    const offset  = RING_CIRCUMFERENCE - ( clamped / 100 ) * RING_CIRCUMFERENCE;
    const ringRef = useRef( null );

    // Animate the stroke from empty → target after mount.
    useEffect( () => {
        const el = ringRef.current;
        if ( ! el ) return;
        el.style.strokeDashoffset = String( RING_CIRCUMFERENCE );
        requestAnimationFrame( () =>
            requestAnimationFrame( () => {
                el.style.strokeDashoffset = String( offset );
            } )
        );
    }, [ offset ] );

    // Derive stroke color when no accent overrides.
    const strokeColor = accent || (
        clamped >= 80 ? '#22c55e' :
        clamped >= 60 ? '#6366f1' :
        clamped >= 40 ? '#f59e0b' : '#ef4444'
    );

    // Breakdown items — static thresholds mirroring backend scoring.
    const items = [
        { label: 'Profile Photo',   pct: clamped >= 20  ? 100 : 0 },
        { label: 'Bio',             pct: clamped >= 40  ? 100 : clamped >= 20 ? 60 : 0 },
        { label: 'Verified Email',  pct: clamped >= 60  ? 100 : clamped >= 40 ? 60 : 0 },
        { label: 'Phone Number',    pct: clamped >= 80  ? 100 : clamped >= 60 ? 50 : 0 },
        { label: 'Two-Factor Auth', pct: clamped >= 100 ? 100 : clamped >= 80 ? 50 : 0 },
    ];

    return createElement( 'div', { className: 'mfd-strength-section' },
        // Header.
        createElement( 'div', { className: 'mfd-strength-header' },
            createElement( 'span', { className: 'mfd-strength-title'  }, purify( i18n.strength || 'Profile Strength' ) ),
            createElement( 'span', { className: 'mfd-strength-badge'  }, `${ clamped }%` )
        ),
        // Ring + bars.
        createElement( 'div', { className: 'mfd-strength-ring-wrap' },
            // SVG ring.
            createElement( 'svg', {
                    className:   'mfd-strength-ring',
                    viewBox:     '0 0 96 96',
                    width:       96,
                    height:      96,
                    role:        'img',
                    'aria-label': `${ purify( i18n.strength || 'Profile Strength' ) }: ${ clamped }%`,
                },
                createElement( 'circle', {
                    className: 'mfd-strength-ring__track',
                    cx: 48, cy: 48, r: RING_RADIUS,
                } ),
                createElement( 'circle', {
                    ref:              ringRef,
                    className:        'mfd-strength-ring__fill',
                    cx: 48, cy: 48,   r: RING_RADIUS,
                    stroke:           strokeColor,
                    strokeDasharray:  String( RING_CIRCUMFERENCE ),
                    strokeDashoffset: String( RING_CIRCUMFERENCE ),
                    style:            { transition: 'stroke-dashoffset 1.2s cubic-bezier(0.4,0,0.2,1), stroke 0.4s ease' },
                } ),
                createElement( 'text', {
                    x: '50%', y: '50%', textAnchor: 'middle',
                    dominantBaseline: 'central',
                    fontSize: '16', fontWeight: '700',
                    fontFamily: 'Poppins, sans-serif',
                    fill: '#1e293b',
                    className: 'mfd-strength-score',
                }, `${ clamped }%` )
            ),
            // Breakdown bars.
            createElement( 'div', { className: 'mfd-strength-items' },
                ...items.map( ( item ) =>
                    createElement( 'div', { className: 'mfd-strength-item', key: item.label },
                        createElement( 'div', { className: 'mfd-strength-item__label' },
                            createElement( 'span', null, purify( item.label ) ),
                            createElement( 'span', null, `${ item.pct }%` )
                        ),
                        createElement( 'div', { className: 'mfd-strength-bar' },
                            createElement( 'div', {
                                className: 'mfd-strength-bar__fill',
                                style:     { width: `${ item.pct }%`, background: accent || '#6366f1' },
                            } )
                        )
                    )
                )
            )
        )
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ActivityGrid — paginated datatable with full DOMPurify sanitization
// ─────────────────────────────────────────────────────────────────────────────

const EVENT_BADGE_CLASS = {
    login:    'mfd-event-badge mfd-event-badge--login',
    download: 'mfd-event-badge mfd-event-badge--download',
    update:   'mfd-event-badge mfd-event-badge--update',
};

function ActivityGrid( { activity, status, page, onPage, accent, i18n } ) {
    const { items = [], total = 0, pages = 1, current_page = 1 } = activity;

    const badgeClass = ( type ) =>
        EVENT_BADGE_CLASS[ type ] || 'mfd-event-badge mfd-event-badge--default';

    /**
     * Format a payload object into a display string.
     * Every key and value is sanitized via purify() — covers device names,
     * file names, download paths, and any arbitrary user-supplied metadata.
     *
     * @param {Object} payload — Raw activity_log payload from REST API.
     * @returns {string} Safe comma-separated "key: value" string.
     */
    const formatPayload = ( payload ) => {
        if ( ! payload || typeof payload !== 'object' ) return '—';
        const safe = purifyObject( payload );
        const parts = Object.entries( safe )
            .map( ( [ k, v ] ) => `${ k }: ${ v }` )
            .filter( Boolean );
        return parts.length ? parts.join( ', ' ) : '—';
    };

    const formatTs = ( ts ) => {
        if ( ! ts ) return '—';
        const d = new Date( ts );
        return isNaN( d.getTime() ) ? purify( ts ) : d.toLocaleString();
    };

    const isLoading = status === 'loading';

    return createElement( 'div', { className: 'mfd-activity-section' },

        // Header.
        createElement( 'div', { className: 'mfd-activity-header' },
            createElement( 'span', { className: 'mfd-activity-title' }, purify( i18n.activity || 'Recent Activity' ) ),
            createElement( 'span', { className: 'mfd-activity-count' },
                `${ total } ${ total === 1 ? 'event' : 'events' }`
            )
        ),

        // Loading overlay.
        isLoading && createElement( 'div', {
            className:  'mfd-activity-empty',
            role:       'status',
            'aria-live': 'polite',
        }, '…' ),

        // Error state.
        ! isLoading && status === 'error' && createElement( 'div', {
            className: 'mfd-activity-empty',
            style:     { color: '#ef4444' },
            role:      'alert',
        },
            'Failed to load activity. ',
            createElement( 'button', {
                className: 'mfd-btn-retry',
                style:     { display: 'inline', padding: '2px 8px', fontSize: '12px' },
                onClick:   () => onPage( page ),
            }, purify( i18n.errorRetry || 'Retry' ) )
        ),

        // Empty state.
        ! isLoading && status !== 'error' && items.length === 0 && createElement( 'div', {
            className: 'mfd-activity-empty',
        }, purify( i18n.noActivity || 'No recent activity recorded.' ) ),

        // Data table.
        ! isLoading && status !== 'error' && items.length > 0 &&
            createElement( 'table', { className: 'mfd-activity-table', role: 'grid' },
                createElement( 'thead', null,
                    createElement( 'tr', null,
                        createElement( 'th', { scope: 'col' }, 'Type'    ),
                        createElement( 'th', { scope: 'col' }, 'Details' ),
                        createElement( 'th', { scope: 'col' }, 'When'    )
                    )
                ),
                createElement( 'tbody', null,
                    ...items.map( ( item, idx ) => {
                        // ── XSS HARDENING ──
                        // purify() every string from the database row before DOM insertion.
                        const safeType    = purify( item.type    || '' );
                        const safeDetail  = formatPayload( item.payload );
                        const safeTs      = formatTs( item.timestamp );

                        return createElement( 'tr', { key: `${ item.timestamp }-${ idx }` },
                            createElement( 'td', null,
                                createElement( 'span', { className: badgeClass( safeType ) }, safeType || '—' )
                            ),
                            createElement( 'td', null, safeDetail ),
                            createElement( 'td', null, safeTs     )
                        );
                    } )
                )
            ),

        // Pagination — only when there are multiple pages.
        pages > 1 && createElement( 'div', { className: 'mfd-pagination' },
            createElement( 'span', { className: 'mfd-pagination__info' },
                `Page ${ current_page } of ${ pages }`
            ),
            createElement( 'div', { className: 'mfd-pagination__controls' },
                createElement( 'button', {
                    className:   'mfd-btn-page',
                    disabled:    current_page <= 1 || isLoading,
                    onClick:     () => onPage( current_page - 1 ),
                    'aria-label': purify( i18n.previous || 'Previous page' ),
                }, purify( i18n.previous || 'Previous' ) ),
                createElement( 'button', {
                    className:   'mfd-btn-page',
                    disabled:    current_page >= pages || isLoading,
                    onClick:     () => onPage( current_page + 1 ),
                    'aria-label': purify( i18n.next || 'Next page' ),
                }, purify( i18n.next || 'Next' ) )
            )
        )
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ErrorState
// ─────────────────────────────────────────────────────────────────────────────

function ErrorState( { title, message, retryLabel, onRetry } ) {
    return createElement( 'div', { className: 'mfd-error-state', role: 'alert' },
        createElement( 'svg', {
                className: 'mfd-error-state__icon', viewBox: '0 0 24 24',
                fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, 'aria-hidden': 'true',
            },
            createElement( 'path', {
                strokeLinecap: 'round', strokeLinejoin: 'round',
                d: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
            } )
        ),
        createElement( 'h3', { className: 'mfd-error-state__title'   }, purify( title   ) ),
        createElement( 'p',  { className: 'mfd-error-state__message' }, purify( message ) ),
        createElement( 'button', { className: 'mfd-btn-retry', onClick: onRetry }, purify( retryLabel ) )
    );
}
