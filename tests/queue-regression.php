<?php
require '/wordpress/wp-load.php';
delete_option( 'lingora_scan_state' );
wp_clear_scheduled_hook( 'lingora_scan_step' );
$checks = array();
function lingora_queue_check( bool $pass, string $label ): void {
	$GLOBALS['checks'][] = array( 'check' => $label, 'pass' => $pass );
	if ( ! $pass ) {
		global $wpdb;
		echo "FAIL: $label\n";
		echo wp_json_encode( array( 'status' => lingora()->db->queue_status(), 'error' => $wpdb->last_error, 'query' => $wpdb->last_query, 'rows' => $wpdb->get_results( 'SELECT * FROM ' . Lingora_Db::queue_table(), ARRAY_A ) ) ) . "\n";
	}
}
global $wpdb;
$db = lingora()->db;
$options = lingora()->options;
$options->update( array( 'auto_translate' => false, 'ai_consent' => false, 'translate_slugs' => false ) );
$options->save_languages( array(
	array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'locale' => 'en_US', 'enabled' => true, 'is_default' => true ),
	array( 'code' => 'de', 'name' => 'German', 'native' => 'Deutsch', 'locale' => 'de_DE', 'enabled' => true, 'is_default' => false ),
) );
$q = Lingora_Db::queue_table();
$t = Lingora_Db::translations_table();
$s = Lingora_Db::strings_table();
$wpdb->query("DELETE FROM $q");
$id = $db->upsert_string( 'Atomic original', 'html', 'home' );
lingora_queue_check( $id === $db->upsert_string( 'Atomic original', 'html', 'home' ), 'upsert returns same exact source ID' );
lingora_queue_check( $db->save_translation( $id, 'de', 'Human value', 'human' ), 'human translation persisted' );
lingora_queue_check( $db->save_translation( $id, 'de', 'Human value', 'human' ), 'identical human save is successful' );
lingora_queue_check( ! $db->save_translation( $id, 'de', 'Auto overwrite', 'auto' ), 'automatic write cannot overwrite human row' );
lingora_queue_check( ! $db->save_translation( 999999, 'de', 'Orphan' ) && ! $db->queue( 999999, 'de' ), 'orphan translation and queue writes rejected' );
$id2 = $db->upsert_string( 'Claimable original', 'html', 'home' );
$db->queue_manual( $id2, 'de' );
$claim = $db->claim_queue( 1, false );
lingora_queue_check( 1 === count( $claim ) && ! $db->claim_queue( 1, false ), 'second worker cannot claim live leased row' );
lingora_queue_check( ! $db->complete_queue( (int) $claim[0]->id, 'wrong-token', 'wrong value' ), 'foreign token cannot complete a task' );
$db->save_translation( $id2, 'de', 'Human during AI', 'human' );
lingora_queue_check( ! $db->complete_queue( (int) $claim[0]->id, $claim[0]->claim_token, 'Late auto value' ), 'late AI cannot replace concurrent human save' );
$id3 = $db->upsert_string( 'Retry original', 'html', 'home' );
$db->queue_manual( $id3, 'de' );
for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
	$claim = $db->claim_queue( 1, false );
	lingora_queue_check( 1 === count( $claim ), 'retry claim ' . $attempt );
	$db->fail_queue( (int) $claim[0]->id, $claim[0]->claim_token, 'Offline failure' );
	$row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $q WHERE string_id=%d", $id3) );
	$delay = strtotime( $row->available_at . ' UTC' ) - time();
	lingora_queue_check( (int) $row->attempts === $attempt && ( $attempt === 5 ? 'failed' : 'pending' ) === $row->status && $delay >= 60, 'retry delay and status ' . $attempt );
	$wpdb->query( $wpdb->prepare("UPDATE $q SET available_at=NULL WHERE string_id=%d",$id3) );
}
lingora_queue_check( ! $db->claim_queue() && 1 === $db->queue_status()['failed'], 'terminal failure remains visible and unclaimed' );
$db->queue_manual( $id3, 'de' );
lingora_queue_check( 0 === $db->queue_status()['failed'] && 1 === $db->queue_status()['pending'], 'explicit manual request revives failed task' );
$claim = $db->claim_queue( 1 );
$wpdb->query( $wpdb->prepare("UPDATE $q SET claimed_at=%s WHERE id=%d",gmdate('Y-m-d H:i:s',time()-1000),$claim[0]->id) );
$reclaimed = $db->claim_queue(1);
lingora_queue_check( 1 === count($reclaimed) && $reclaimed[0]->claim_token !== $claim[0]->claim_token, 'expired worker lease can be reclaimed' );
lingora_queue_check( ! $db->complete_queue((int)$claim[0]->id,$claim[0]->claim_token,'Stale'), 'expired token remains fenced after reclaim' );
$db->complete_queue((int)$reclaimed[0]->id,$reclaimed[0]->claim_token,'Recovered');
$GLOBALS['lingora_fixture_requests'] = array();
lingora()->translator->resolve( array( 'Automatic disabled fixture' ), 'de', 'home', true );
lingora_queue_check( 0 === $db->stats()['queued'] && ! $GLOBALS['lingora_fixture_requests'], 'automatic off means no automatic enqueue or network' );
lingora()->translator->enqueue_originals( array('Manual disabled fixture'), 'de', 'home', true );
lingora_queue_check( $db->queue_status()['manual'] >= 1 && 0 === lingora()->translator->process_queue(), 'manual enqueue allowed while consent blocks worker' );
$options->update( array( 'ai_consent' => true, 'ai' => array( 'provider_id'=>'custom','api_key'=>'test-queue-secret','base_url'=>'https://fixtures.lingora.invalid/v1','model'=>'fixture-model' ) ) );
lingora_queue_check( 1 === lingora()->translator->process_queue(), 'explicit manual worker runs with automatic off' );
$post = wp_insert_post( array('post_title'=>'Privacy transition','post_status'=>'publish') );
$private_id = $db->upsert_string('Source that becomes private','html','post:'.$post);
$db->queue_manual($private_id,'de');
wp_update_post(array('ID'=>$post,'post_status'=>'private'));
$count=count($GLOBALS['lingora_fixture_requests']);
lingora()->translator->process_queue();
lingora_queue_check( null !== $db->get_string($private_id) && ! $db->map_for_language(array($db->hash('Source that becomes private')),'de') && count($GLOBALS['lingora_fixture_requests'])===$count, 'privacy transition keeps dictionary but blocks lookup and sending' );
$wpdb->query("DELETE FROM $q");
$options->update(array('ai_consent'=>false,'auto_translate'=>false));
for($i=0;$i<45;$i++) {
	wp_insert_post(array('post_title'=>'Scan fixture '.$i,'post_content'=>'<p>Segment A '.$i.'</p><p>Segment B '.$i.'</p>','post_status'=>'publish'));
}
$protected=wp_insert_post(array('post_title'=>'Password scan secret','post_content'=>'Never collect password content','post_status'=>'publish','post_password'=>'secret'));
$short=wp_insert_post(array('post_title'=>'Shortcode exclusion','post_content'=>'[private]Hidden shortcode secret[/private]','post_status'=>'publish'));
$start=lingora()->scanner->trigger_scan();
$duplicate=lingora()->scanner->trigger_scan();
lingora_queue_check($start['started'] && !$duplicate['started'] && $duplicate['already_running'],'scan trigger is idempotent');
for($i=0;$i<10 && 'running'===lingora()->scanner->scan_status()['status'];$i++) {
	wp_clear_scheduled_hook('lingora_scan_step');
	lingora()->scanner->scan_step();
}
$scan=lingora()->scanner->scan_status();
lingora_queue_check('done'===$scan['status'] && $scan['scanned']>=45,'scan cursor covers more than forty public posts');
lingora_queue_check(null!==$db->get_string_by_hash($db->hash('Segment A 44')) && null!==$db->get_string_by_hash($db->hash('Segment B 44')),'scanner extracts separate DOM segments');
lingora_queue_check(null===$db->get_string_by_hash($db->hash('Never collect password content')) && null===$db->get_string_by_hash($db->hash('Hidden shortcode secret')),'scanner excludes password and shortcode content');
lingora_queue_check(0===$db->stats()['queued'],'scan with automatic off stores but does not enqueue');
$options->update(array('translate_slugs'=>true));
$parent=wp_insert_post(array('post_type'=>'page','post_title'=>'Parent','post_name'=>'parent','post_status'=>'publish'));
$child=wp_insert_post(array('post_type'=>'page','post_title'=>'Child','post_name'=>'child','post_status'=>'publish','post_parent'=>$parent));
foreach(array('parent'=>'Eltern','child'=>'你好') as $original=>$translated) {
	$slug=$db->get_string_by_hash($db->hash($original),'slug');
	$db->save_translation((int)$slug->id,'de',Lingora_Slugs::normalize_slug($translated),'human');
}
// 在首次 map_request 前注册：查询变量列表按请求缓存，生产中 CPT 均注册于路由前。
register_post_type('lingora_event',array('public'=>true,'rewrite'=>array('slug'=>'lingora-events')));
$GLOBALS['lingora_language']='de';
$url=lingora()->slugs->filter_page_link('https://example.test/de/parent/child/?x=1#end',$child);
lingora_queue_check('https://example.test/de/eltern/%E4%BD%A0%E5%A5%BD/?x=1#end'===$url,'hierarchical slug URL encodes Unicode once');
$vars=lingora()->slugs->map_request(array('pagename'=>'eltern/%E4%BD%A0%E5%A5%BD'));
lingora_queue_check('parent/child'===$vars['pagename'],'hierarchical translated slug resolves both segments');
$event=wp_insert_post(array('post_type'=>'lingora_event','post_title'=>'Event','post_name'=>'conference','post_status'=>'publish'));
$event_row=$db->get_string_by_hash($db->hash('conference'),'slug');
lingora_queue_check($event>0&&null!==$event_row,'custom post type slug collected');
if($event_row){$db->save_translation((int)$event_row->id,'de','konferenz','human');}
$vars=lingora()->slugs->map_request(array('lingora_event'=>'konferenz'));
lingora_queue_check(isset($vars['lingora_event'])&&'conference'===$vars['lingora_event'],'custom post type translated slug restored',wp_json_encode($vars));
$collide1=$db->upsert_string('collide-a','slug','home');
$collide2=$db->upsert_string('collide-b','slug','home');
$db->save_translation($collide1,'de','gemeinsam','human');
$db->save_translation($collide2,'de','gemeinsam','human');
lingora_queue_check('collide-a'===$db->original_for_translation('gemeinsam','de','slug'),'slug collision resolves deterministically');
$wpost=wp_insert_post(array('post_title'=>'Withdrawn fixture','post_name'=>'withdrawn-slug','post_status'=>'publish'));
$withdrawn=$db->upsert_string('withdrawn-slug','slug','post:'.$wpost);
$db->save_translation($withdrawn,'de','zurueckgezogen','human');
lingora_queue_check('withdrawn-slug'===$db->original_for_translation('zurueckgezogen','de','slug'),'public slug reverse-maps before withdrawal');
wp_update_post(array('ID'=>$wpost,'post_status'=>'private'));
lingora_queue_check(''===$db->original_for_translation('zurueckgezogen','de','slug'),'withdrawn source excluded from reverse mapping');
lingora_queue_check(Lingora_Translator::is_translatable('字') && !Lingora_Translator::is_translatable('a'),'single CJK character is translatable');
$version=get_option('lingora_db_version');
Lingora_Db::install();
lingora_queue_check($version===get_option('lingora_db_version') && null!==$db->get_string($id),'schema migration repeat preserves dictionary');
echo wp_json_encode(array('suite'=>'queue','checks'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit(in_array(false,array_column($checks,'pass'),true)?1:0);
