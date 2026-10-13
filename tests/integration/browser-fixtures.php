<?php
/** Seed synthetic UI fixtures only in the disposable test site. */
require '/wordpress/wp-load.php';
$options = lingora()->options;
$options->update( array( 'auto_translate' => false, 'translate_on_fly' => false, 'ai_consent' => false, 'detect_browser' => false, 'remember_language' => false, 'translate_slugs' => false ) );
$options->save_languages( array(
	array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'locale' => 'en_US', 'enabled' => true, 'is_default' => true, 'flag' => 'us' ),
	array( 'code' => 'de', 'name' => 'German', 'native' => 'Deutsch', 'locale' => 'de_DE', 'enabled' => true, 'is_default' => false, 'flag' => 'de' ),
) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'lingora-preview', 'post_title' => 'Lingora public preview', 'post_content' => '<p>Welcome to Lingora</p><p>A public sample for visual translation.</p><p><input type="text" placeholder="Search products"></p>' ) );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $page );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );
foreach ( array( 'Welcome to Lingora' => 'Willkommen bei Lingora', 'A public sample for visual translation.' => 'Ein öffentliches Beispiel für visuelle Übersetzung.', 'Search products' => 'Produkte suchen' ) as $original => $translation ) {
	$id = lingora()->db->upsert_string( $original, 'html', 'post:' . $page );
	lingora()->db->save_translation( $id, 'de', $translation, 'human' );
}
$options->update_ai( array( 'provider_id' => 'custom', 'base_url' => 'https://fixtures.lingora.invalid/v1', 'api_key' => 'sandbox-ui-key', 'model' => 'lingora-fixture-model' ) );
wp_set_password( 'password', get_user_by( 'login', 'admin' )->ID );
echo wp_json_encode( array( 'home' => home_url('/'), 'target' => home_url('/de/'), 'admin' => admin_url('admin.php?page=lingora'), 'credentials' => 'admin / password; disposable sandbox only' ) );
