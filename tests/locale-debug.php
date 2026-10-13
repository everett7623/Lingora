<?php
require '/wordpress/wp-load.php';
$values = array();
foreach ( array( 'de_DE', 'pt_BR', 'zh_TW', 'fr_FR', '' ) as $locale ) {
	update_option( 'WPLANG', $locale );
	$values[] = array( 'input' => $locale, 'stored' => get_option( 'WPLANG', '__missing__' ), 'default' => Lingora_Languages::default_set()[0] );
}
echo wp_json_encode( $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
