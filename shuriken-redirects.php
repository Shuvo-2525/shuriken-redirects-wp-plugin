<?php
/**
 * Plugin Name: Shuriken Redirects
 * Description: Manage root-level redirects with click tracking.
 * Version: 1.1
 * Author: Shuriken Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 1. Register the Custom Post Type
 * We set 'public' to false so it doesn't interfere with WP's default archive pages,
 * but we will handle the routing manually for Root URLs.
 */
function shuriken_register_cpt() {
    $labels = array(
        'name'               => 'Redirects',
        'singular_name'      => 'Redirect',
        'menu_name'          => 'Shuriken Redirects',
        'add_new'            => 'Add New Link',
        'add_new_item'       => 'Add New Redirect Link',
        'edit_item'          => 'Edit Redirect',
        'new_item'           => 'New Redirect',
        'all_items'          => 'All Redirects',
        'search_items'       => 'Search Redirects',
        'not_found'          => 'No redirects found',
        'not_found_in_trash' => 'No redirects found in Trash'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,  // Hidden from frontend search/archives
        'show_ui'            => true,   // Show in Admin Dashboard
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-randomize', // Icon looks like a crossover/redirect
        'supports'           => array( 'title' ), // We only need the Title (which becomes the Slug)
        'capability_type'    => 'post',
        'rewrite'            => false, // We handle rewrite manually
    );

    register_post_type( 'shuriken_url', $args );
}
add_action( 'init', 'shuriken_register_cpt' );

/**
 * 2. Add Meta Boxes (The Input Fields)
 */
function shuriken_add_meta_boxes() {
    // Box for Target URL
    add_meta_box(
        'shuriken_target_url_box',
        'Target URL (Where should users go?)',
        'shuriken_render_target_url_box',
        'shuriken_url',
        'normal',
        'high'
    );

    // Box for Stats
    add_meta_box(
        'shuriken_stats_box',
        'Analytics',
        'shuriken_render_stats_box',
        'shuriken_url',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'shuriken_add_meta_boxes' );

// Render the Target URL Input
function shuriken_render_target_url_box( $post ) {
    $value = get_post_meta( $post->ID, '_shuriken_target_url', true );
    ?>
    <p>
        <label for="shuriken_target_url">Enter destination link (e.g., https://forms.gle/...):</label>
        <input type="url" id="shuriken_target_url" name="shuriken_target_url" value="<?php echo esc_attr( $value ); ?>" style="width:100%;" placeholder="https://" required>
    </p>
    <?php
}

// Render the Stats Box
function shuriken_render_stats_box( $post ) {
    $clicks = get_post_meta( $post->ID, '_shuriken_clicks', true );
    if ( ! $clicks ) { $clicks = 0; }
    echo '<h3><strong>' . esc_html( $clicks ) . '</strong> Clicks</h3>';
    echo '<p class="description">Total times this link has been visited.</p>';
}

/**
 * 3. Save Data & Validate Slug
 */
function shuriken_save_postdata( $post_id ) {
    // Check auto-save or permissions
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // 1. Save Target URL
    if ( isset( $_POST['shuriken_target_url'] ) ) {
        update_post_meta( $post_id, '_shuriken_target_url', esc_url_raw( $_POST['shuriken_target_url'] ) );
    }

    // 2. Slug Conflict Validation
    // We get the slug that is about to be saved
    $post = get_post($post_id);
    $slug = $post->post_name;

    if ( ! empty($slug) ) {
        // Check if this slug exists as a Page or Post
        // We exclude the current post ID from the check
        $conflict = false;
        
        // Check Pages/Posts
        $existing_page = get_page_by_path( $slug, OBJECT, array('post', 'page') );
        
        if ( $existing_page && $existing_page->ID != $post_id ) {
            $conflict = true;
        }

        if ( $conflict ) {
            // Mark validation error in a transient to show admin notice
            set_transient( 'shuriken_slug_error_' . $post_id, $slug, 45 );
            
            // Optional: Unpublish it to prevent breakage? 
            // For now, we just warn the user.
        }
    }
}
add_action( 'save_post_shuriken_url', 'shuriken_save_postdata' );

/**
 * 4. Display Admin Notice if Slug Conflict
 */
function shuriken_admin_notices() {
    $screen = get_current_screen();
    if ( 'shuriken_url' !== $screen->post_type ) return;

    global $post;
    if ( ! $post ) return;

    if ( get_transient( 'shuriken_slug_error_' . $post->ID ) ) {
        $slug = get_transient( 'shuriken_slug_error_' . $post->ID );
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>WARNING:</strong> The slug "<?php echo esc_html($slug); ?>" is already used by a Page or Post on this site.</p>
            <p>This redirect might not work until you change the Title/Slug to something unique.</p>
        </div>
        <?php
        delete_transient( 'shuriken_slug_error_' . $post->ID );
    }
}
add_action( 'admin_notices', 'shuriken_admin_notices' );

/**
 * 5. Admin Columns (Show URL and Clicks in Dashboard List)
 */
function shuriken_manage_columns( $columns ) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = 'Redirect Name (Slug)';
    $new_columns['public_link'] = 'Get Link'; // Added Column
    $new_columns['target'] = 'Target URL';
    $new_columns['clicks'] = 'Clicks';
    $new_columns['date'] = $columns['date'];
    return $new_columns;
}
add_filter( 'manage_shuriken_url_posts_columns', 'shuriken_manage_columns' );

function shuriken_populate_columns( $column, $post_id ) {
    // New Column Logic
    if ( 'public_link' === $column ) {
        $slug = get_post_field( 'post_name', $post_id );
        // Construct the full URL based on the site home URL and the slug
        $full_link = home_url( '/' . $slug );
        
        // Output a readonly input for easy copying
        echo '<input type="text" value="' . esc_attr( $full_link ) . '" readonly onclick="this.select();" style="width: 100%; max-width: 250px; background: #f0f0f1; border: 1px solid #ccc;">';
    }

    if ( 'target' === $column ) {
        $url = get_post_meta( $post_id, '_shuriken_target_url', true );
        echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>';
    }
    if ( 'clicks' === $column ) {
        $clicks = get_post_meta( $post_id, '_shuriken_clicks', true );
        echo $clicks ? esc_html( $clicks ) : '0';
    }
}
add_action( 'manage_shuriken_url_posts_custom_column', 'shuriken_populate_columns', 10, 2 );

/**
 * 6. The Redirection Logic (The Engine)
 * This runs on every page load to check if we need to redirect.
 */
function shuriken_handle_redirect() {
    if ( is_admin() ) return;

    // Get the current requested path (e.g., "registration" from domain.com/registration)
    global $wp;
    $request_slug = $wp->request;

    // Clean the slug
    $request_slug = trim( $request_slug, '/' );

    if ( empty( $request_slug ) ) return;

    // Search for a 'shuriken_url' post with this slug
    $args = array(
        'name'           => $request_slug,
        'post_type'      => 'shuriken_url',
        'post_status'    => 'publish',
        'posts_per_page' => 1
    );

    $redirect_query = new WP_Query( $args );

    if ( $redirect_query->have_posts() ) {
        $redirect_query->the_post();
        $post_id = get_the_ID();
        
        // Get Target
        $target_url = get_post_meta( $post_id, '_shuriken_target_url', true );

        if ( $target_url ) {
            // 1. Increment Counter
            $current_clicks = (int) get_post_meta( $post_id, '_shuriken_clicks', true );
            update_post_meta( $post_id, '_shuriken_clicks', $current_clicks + 1 );

            // 2. Perform Redirect
            wp_redirect( $target_url, 301 );
            exit;
        }
    }
    wp_reset_postdata();
}
add_action( 'template_redirect', 'shuriken_handle_redirect', 1 );