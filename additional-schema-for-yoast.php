<?php
/**
 * Plugin Name: Additional Schema for Yoast SEO
 * Description: Adds a metabox to inject custom JSON-LD schema data into Yoast SEO's structured data output.
 * Version: 1.0.0
 * Author: Brandon Logan
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Additional_Schema_For_Yoast {

    const META_KEY = '_additional_schema_data';
    const NONCE    = 'additional_schema_nonce';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ], 99 );
        add_action( 'save_post',      [ $this, 'save_metabox' ] );
        add_filter( 'wpseo_schema_graph', [ $this, 'merge_schema' ], 99, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /**
     * Register the metabox on all public post types.
     */
    public function register_metabox() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'additional_schema_metabox',
                'Additional Schema',
                [ $this, 'render_metabox' ],
                $post_type,
                'normal',
                'low' // low priority places it after Yoast
            );
        }
    }

    /**
     * Render the metabox UI.
     */
    public function render_metabox( $post ) {
        $stored = get_post_meta( $post->ID, self::META_KEY, true );
        wp_nonce_field( self::NONCE, self::NONCE . '_field' );
        ?>
        <div class="additional-schema-wrap">
            <p class="description" style="margin-bottom:8px;">
                Paste one or more JSON-LD schema objects below. They will be merged into
                Yoast SEO's structured data output for this page. Enter <strong>valid JSON</strong> —
                either a single object <code>{ … }</code> or an array of objects <code>[ { … }, { … } ]</code>.
                Do not include the <code>&lt;script&gt;</code> tag.
            </p>
            <textarea
                id="additional-schema-textarea"
                name="additional_schema_data"
                rows="14"
                style="width:100%;font-family:monospace;font-size:13px;tab-size:2;"
                spellcheck="false"
                placeholder='Example:
{
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "What is this?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "An example."
      }
    }
  ]
}'
            ><?php echo esc_textarea( $stored ); ?></textarea>
            <div id="additional-schema-status" style="margin-top:6px;font-size:13px;"></div>
        </div>
        <?php
    }

    /**
     * Inline admin JS for live JSON validation.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        wp_add_inline_script( 'jquery-core', "
            jQuery(function($){
                var ta = $('#additional-schema-textarea');
                var st = $('#additional-schema-status');
                if (!ta.length) return;
                function validate(){
                    var v = ta.val().trim();
                    if (!v) { st.html(''); return; }
                    try {
                        var parsed = JSON.parse(v);
                        if (typeof parsed !== 'object' || parsed === null) throw 'not object';
                        st.html('<span style=\"color:#00a32a;\">&#10003; Valid JSON</span>');
                    } catch(e) {
                        st.html('<span style=\"color:#d63638;\">&#10007; Invalid JSON — please check your syntax</span>');
                    }
                }
                ta.on('input', validate);
                validate();
            });
        " );
    }

    /**
     * Save the metabox data.
     */
    public function save_metabox( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE . '_field' ] ) ||
             ! wp_verify_nonce( $_POST[ self::NONCE . '_field' ], self::NONCE ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['additional_schema_data'] ) ) {
            $raw = sanitize_textarea_field( wp_unslash( $_POST['additional_schema_data'] ) );
            update_post_meta( $post_id, self::META_KEY, $raw );
        }
    }

    /**
     * Merge custom schema into Yoast's @graph array.
     *
     * Hooks into wpseo_schema_graph so everything stays inside
     * Yoast's single JSON-LD block — no duplicate script tags.
     */
    public function merge_schema( $graph, $context ) {
        $post_id = is_singular() ? get_queried_object_id() : 0;

        if ( ! $post_id ) {
            return $graph;
        }

        $raw = get_post_meta( $post_id, self::META_KEY, true );

        if ( empty( $raw ) ) {
            return $graph;
        }

        $decoded = json_decode( $raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            return $graph;
        }

        // Normalise: if it's a single object, wrap it in an array.
        if ( isset( $decoded['@type'] ) ) {
            $decoded = [ $decoded ];
        }

        // If the user pasted a full JSON-LD wrapper with @graph, unwrap it.
        if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
            $decoded = $decoded['@graph'];
        }

        foreach ( $decoded as $piece ) {
            if ( is_array( $piece ) && isset( $piece['@type'] ) ) {
                $graph[] = $piece;
            }
        }

        return $graph;
    }
}

new Additional_Schema_For_Yoast();
