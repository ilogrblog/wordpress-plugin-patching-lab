<?php
/**
 * Makes the article's one-line access-control changes in a copy of a specimen plugin.
 *
 * Runs inside the lab's WP-CLI container only (bin/run-lab.sh calls it). The repository stores the
 * plugins exactly as the models wrote them; the weakened copies exist only inside the throwaway lab.
 *
 * Usage: php mutate.php public-rest|no-capability-check SOURCE DESTINATION
 */

[, $mode, $src, $dst] = $argv + [null, null, null, null];

$changes = [
	// Haiku plugin: the REST route becomes public.
	'public-rest'         => [
		"'permission_callback' => 'qqr_rest_permissions'",
		"'permission_callback' => '__return_true'",
	],
	// Sonnet plugin: the delete handler keeps its nonce check but loses its capability check.
	'no-capability-check' => [
		"\t\tif ( ! current_user_can( 'manage_options' ) ) {\n"
		. "\t\t\twp_die( esc_html__( 'You do not have permission to do that.', 'quick-quote-requests' ), '', array( 'response' => 403 ) );\n"
		. "\t\t}\n"
		. "\t\tcheck_admin_referer(",
		"\t\tcheck_admin_referer(",
	],
];

if ( ! isset( $changes[ $mode ] ) || ! is_readable( (string) $src ) || ! $dst ) {
	fwrite( STDERR, "usage: php mutate.php public-rest|no-capability-check SOURCE DESTINATION\n" );
	exit( 2 );
}

[ $from, $to ] = $changes[ $mode ];
$code          = file_get_contents( $src );
$found         = substr_count( $code, $from );
if ( 1 !== $found ) {
	fwrite( STDERR, "expected exactly one match for {$mode}, found {$found}\n" );
	exit( 1 );
}

if ( ! is_dir( dirname( $dst ) ) ) {
	mkdir( dirname( $dst ), 0755, true );
}
file_put_contents( $dst, str_replace( $from, $to, $code ) );
echo "made the {$mode} change in a copy\n";
