/**
 * edit.js — MFD Dashboard Widget
 *
 * Gutenberg block Edit component — rendered exclusively inside the
 * WordPress block editor (wp-admin). Never runs on the frontend.
 *
 * Responsibilities:
 *   - Render InspectorControls sidebar panel for administrators.
 *   - Allow toggling visibility of each dashboard widget (Last Login,
 *     Profile Strength, Downloads, Activity).
 *   - Allow picking an accent color (applied via CSS custom property).
 *   - Render a static, read-only editor preview that mirrors the frontend
 *     layout without requiring a live API call.
 *
 * All attribute mutations use the `useBlockProps` / `setAttributes` pattern
 * from @wordpress/block-editor — never direct state mutation.
 */

import { __ }                          from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
    PanelBody,
    PanelRow,
    ToggleControl,
    ColorPicker,
    SelectControl,
    __experimentalText as Text,
} from '@wordpress/components';
import { Icon, people, clock, download, list } from '@wordpress/icons';

/**
 * Edit component.
 *
 * @param {Object}   props               Block props injected by WordPress.
 * @param {Object}   props.attributes    Current block attribute values.
 * @param {Function} props.setAttributes Stable attribute mutation function.
 * @returns {JSX.Element}
 */
export default function Edit( { attributes, setAttributes } ) {
    const {
        showLastLogin,
        showStrength,
        showDownloads,
        showActivity,
        accentColor,
        containerWidth,
    } = attributes;

    // useBlockProps applies data-block attributes and Gutenberg wrapper classes.
    const blockProps = useBlockProps( {
        className: 'mfd-editor-root',
        style: { '--mfd-accent': accentColor },
    } );

    return (
        <>
            { /* ----------------------------------------------------------------
                InspectorControls — rendered in the editor sidebar only.
                ---------------------------------------------------------------- */ }
            <InspectorControls>

                { /* Widget Visibility Panel */ }
                <PanelBody
                    title={ __( 'Widget Visibility', 'mfd-dashboard-widget' ) }
                    initialOpen={ true }
                    className="mfd-inspector__section"
                >
                    <PanelRow>
                        <ToggleControl
                            label={ __( 'Last Login', 'mfd-dashboard-widget' ) }
                            help={ showLastLogin
                                ? __( 'Displayed — shows UTC login timestamp.', 'mfd-dashboard-widget' )
                                : __( 'Hidden — last login metric is not shown.', 'mfd-dashboard-widget' )
                            }
                            checked={ showLastLogin }
                            onChange={ ( val ) => setAttributes( { showLastLogin: val } ) }
                        />
                    </PanelRow>
                    <PanelRow>
                        <ToggleControl
                            label={ __( 'Profile Strength Meter', 'mfd-dashboard-widget' ) }
                            help={ showStrength
                                ? __( 'Displayed — animated SVG completion ring.', 'mfd-dashboard-widget' )
                                : __( 'Hidden — completion ring is not shown.', 'mfd-dashboard-widget' )
                            }
                            checked={ showStrength }
                            onChange={ ( val ) => setAttributes( { showStrength: val } ) }
                        />
                    </PanelRow>
                    <PanelRow>
                        <ToggleControl
                            label={ __( 'Downloads Counter', 'mfd-dashboard-widget' ) }
                            help={ showDownloads
                                ? __( 'Displayed — cumulative download total.', 'mfd-dashboard-widget' )
                                : __( 'Hidden.', 'mfd-dashboard-widget' )
                            }
                            checked={ showDownloads }
                            onChange={ ( val ) => setAttributes( { showDownloads: val } ) }
                        />
                    </PanelRow>
                    <PanelRow>
                        <ToggleControl
                            label={ __( 'Recent Activity Grid', 'mfd-dashboard-widget' ) }
                            help={ showActivity
                                ? __( 'Displayed — paginated activity datatable.', 'mfd-dashboard-widget' )
                                : __( 'Hidden.', 'mfd-dashboard-widget' )
                            }
                            checked={ showActivity }
                            onChange={ ( val ) => setAttributes( { showActivity: val } ) }
                        />
                    </PanelRow>
                </PanelBody>

                { /* Layout Panel */ }
                <PanelBody
                    title={ __( 'Layout', 'mfd-dashboard-widget' ) }
                    initialOpen={ false }
                >
                    <PanelRow>
                        <SelectControl
                            label={ __( 'Container Width', 'mfd-dashboard-widget' ) }
                            value={ containerWidth }
                            options={ [
                                { label: __( 'Full Width', 'mfd-dashboard-widget' ),   value: 'full' },
                                { label: __( 'Wide',       'mfd-dashboard-widget' ),   value: 'wide' },
                                { label: __( 'Normal',     'mfd-dashboard-widget' ),   value: 'normal' },
                            ] }
                            onChange={ ( val ) => setAttributes( { containerWidth: val } ) }
                        />
                    </PanelRow>
                </PanelBody>

                { /* Accent Color Panel */ }
                <PanelBody
                    title={ __( 'Accent Color', 'mfd-dashboard-widget' ) }
                    initialOpen={ false }
                >
                    <p className="mfd-inspector__description">
                        { __( 'Applied to the strength meter ring, metric card accents, and active state indicators.', 'mfd-dashboard-widget' ) }
                    </p>
                    <ColorPicker
                        color={ accentColor }
                        onChange={ ( val ) => setAttributes( { accentColor: val } ) }
                        enableAlpha={ false }
                        defaultValue="#6366f1"
                    />
                    <div className="mfd-color-preview">
                        <span
                            className="mfd-color-preview__swatch"
                            style={ { backgroundColor: accentColor } }
                            aria-hidden="true"
                        />
                        <span className="mfd-color-preview__label">{ accentColor }</span>
                    </div>
                </PanelBody>

            </InspectorControls>

            { /* ----------------------------------------------------------------
                Editor Preview Shell
                Static, read-only representation of the frontend block layout.
                Reflects toggle state so admins can see what will be visible.
                ---------------------------------------------------------------- */ }
            <div { ...blockProps }>
                <div
                    className="mfd-editor-preview"
                    style={ { '--mfd-accent': accentColor } }
                    role="img"
                    aria-label={ __( 'Dashboard widget preview', 'mfd-dashboard-widget' ) }
                >
                    { /* Profile header */ }
                    <div className="mfd-editor-preview__header">
                        <div className="mfd-editor-preview__avatar-placeholder">
                            <Icon
                                icon={ people }
                                className="mfd-editor-preview__avatar-icon"
                                size={ 24 }
                            />
                        </div>
                        <div>
                            <div className="mfd-editor-preview__name">
                                { __( 'Member Name', 'mfd-dashboard-widget' ) }
                            </div>
                            <div className="mfd-editor-preview__sub">
                                { __( 'member@example.com', 'mfd-dashboard-widget' ) }
                            </div>
                        </div>
                    </div>

                    { /* Metric cards */ }
                    <div className="mfd-editor-preview__cards">
                        <EditorMetricCard
                            icon={ clock }
                            label={ __( 'Last Login', 'mfd-dashboard-widget' ) }
                            value={ __( '2h ago', 'mfd-dashboard-widget' ) }
                            hidden={ ! showLastLogin }
                            accent={ accentColor }
                        />
                        <EditorMetricCard
                            icon={ download }
                            label={ __( 'Downloads', 'mfd-dashboard-widget' ) }
                            value="47"
                            hidden={ ! showDownloads }
                            accent={ accentColor }
                        />
                        <EditorMetricCard
                            icon={ people }
                            label={ __( 'Strength', 'mfd-dashboard-widget' ) }
                            value="72%"
                            hidden={ ! showStrength }
                            accent={ accentColor }
                        />
                    </div>

                    { /* Strength meter */ }
                    { showStrength && (
                        <div style={ { marginBottom: '16px' } }>
                            <div className="mfd-editor-preview__widget-badge">
                                { __( 'Profile Strength', 'mfd-dashboard-widget' ) }
                            </div>
                            <div className="mfd-editor-preview__meter">
                                <div
                                    className="mfd-editor-preview__meter-fill"
                                    style={ { background: `linear-gradient(90deg, ${ accentColor }, ${ accentColor }aa)` } }
                                />
                            </div>
                        </div>
                    ) }

                    { /* Activity placeholder */ }
                    { showActivity && (
                        <div className="mfd-editor-preview__widget-badge">
                            <Icon icon={ list } size={ 12 } />
                            { __( 'Recent Activity — live data on frontend', 'mfd-dashboard-widget' ) }
                        </div>
                    ) }

                    { /* Notice when all widgets are hidden */ }
                    { ! showLastLogin && ! showStrength && ! showDownloads && ! showActivity && (
                        <p style={ { textAlign: 'center', color: '#94a3b8', fontSize: '13px', padding: '12px 0' } }>
                            { __( 'All widgets are hidden. Enable at least one in the sidebar.', 'mfd-dashboard-widget' ) }
                        </p>
                    ) }
                </div>
            </div>
        </>
    );
}

// ---------------------------------------------------------------------------
// Sub-component: EditorMetricCard
// Renders a single read-only metric card in the editor preview.
// ---------------------------------------------------------------------------

/**
 * @param {Object}  props
 * @param {Object}  props.icon    @wordpress/icons icon object.
 * @param {string}  props.label   Card label string.
 * @param {string}  props.value   Display value string.
 * @param {boolean} props.hidden  If true, card renders with opacity.
 * @param {string}  props.accent  Accent hex color.
 * @returns {JSX.Element}
 */
function EditorMetricCard( { icon, label, value, hidden, accent } ) {
    return (
        <div className={ `mfd-editor-preview__card${ hidden ? ' mfd-editor-preview__card--hidden' : '' }` }>
            <div style={ { display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '8px' } }>
                <span style={ { color: hidden ? '#cbd5e1' : accent } }>
                    <Icon icon={ icon } size={ 14 } />
                </span>
                <span className="mfd-editor-preview__card-label">{ label }</span>
            </div>
            <div
                className="mfd-editor-preview__card-value"
                style={ { color: hidden ? '#cbd5e1' : undefined } }
            >
                { hidden ? '—' : value }
            </div>
        </div>
    );
}
