/**
 * index.js — MFD Dashboard Widget
 *
 * Block editor entry point. Imports CSS and registers the block type
 * using the block.json metadata as the single source of truth.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-blocks/#registerBlockType
 */

import { registerBlockType } from '@wordpress/blocks';

// Block metadata — consumed from block.json at build time.
import metadata from '../../block.json';

// Editor-only styles.
import '../css/editor.css';

// Block edit component (InspectorControls + editor preview).
import Edit from './edit';

// Register the block type with WordPress core.
// The save function returns null because this is a dynamic block —
// all frontend output is handled by PHP's render_callback (RenderCallback.php).
registerBlockType( metadata.name, {
    edit: Edit,
    save: () => null,
} );
