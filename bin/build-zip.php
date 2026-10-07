<?php
/**
 * Builds the installable zip: dist/woonyx-<version>.zip.
 *
 * Usage: composer build
 *
 * The files are those `git archive` would ship (tracked and untracked files that aren't ignored, minus the
 * `export-ignore` paths of .gitattributes), taken from a temporary index so nothing has to be committed or staged.
 * Composer then installs the production autoloader (no dev packages) into the copy, which is zipped under `woonyx/`.
 *
 * @package WooNyx
 */

declare(strict_types=1);

const WOONYX_BUILD_SLUG = 'woonyx';

/**
 * Runs a command in the repository and returns its output; stops the build when it fails.
 *
 * @param string $command Shell command.
 */
function woonyx_build_run( string $command ): string {
	exec( $command . ' 2>&1', $output, $status );

	if ( 0 !== $status ) {
		fwrite( STDERR, "Failed ({$status}): {$command}\n" . implode( "\n", $output ) . "\n" );
		exit( 1 );
	}

	return implode( "\n", $output );
}

/**
 * Deletes a directory and its contents.
 *
 * @param string $dir Directory.
 */
function woonyx_build_remove( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

$root = dirname( __DIR__ );
chdir( $root );

if ( 1 !== preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( 'woonyx.php' ), $match ) ) {
	fwrite( STDERR, "No Version header in woonyx.php.\n" );
	exit( 1 );
}
$version = $match[1];

$build   = $root . '/build';
$plugin  = $build . '/' . WOONYX_BUILD_SLUG;
$archive = $build . '/source.zip';
$zip     = $root . '/dist/' . WOONYX_BUILD_SLUG . '-' . $version . '.zip';

woonyx_build_remove( $build );
mkdir( $build, 0777, true );
if ( ! is_dir( dirname( $zip ) ) ) {
	mkdir( dirname( $zip ), 0777, true );
}

// The working tree as a tree object, through an index of its own (the real index and HEAD stay untouched).
putenv( 'GIT_INDEX_FILE=' . $build . '/index' );
woonyx_build_run( 'git add --all' );
$tree = trim( woonyx_build_run( 'git write-tree' ) );
putenv( 'GIT_INDEX_FILE' );

woonyx_build_run( 'git archive --format=zip -o ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $tree ) );

$source = new ZipArchive();
if ( true !== $source->open( $archive ) || ! $source->extractTo( $plugin ) ) {
	fwrite( STDERR, "Could not extract {$archive}.\n" );
	exit( 1 );
}
$source->close();

woonyx_build_run(
	'composer install --no-dev --classmap-authoritative --no-interaction --no-progress --working-dir=' . escapeshellarg( $plugin )
);

if ( is_file( $zip ) ) {
	unlink( $zip );
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
$paths = array();
foreach ( $files as $file ) {
	$paths[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $plugin ) + 1 ) );
}
sort( $paths );

$out = new ZipArchive();
if ( true !== $out->open( $zip, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create {$zip}.\n" );
	exit( 1 );
}
foreach ( $paths as $path ) {
	$out->addFile( $plugin . '/' . $path, WOONYX_BUILD_SLUG . '/' . $path );
}
$out->close();

woonyx_build_remove( $build );

printf( "%s\n%d files, %s bytes, sha256 %s\n", $zip, count( $paths ), number_format( (int) filesize( $zip ) ), hash_file( 'sha256', $zip ) );
echo implode( "\n", $paths ), "\n";
