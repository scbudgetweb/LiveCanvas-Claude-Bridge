<?php
/**
 * The template library: folders on this Mac where the user keeps HTML templates (set once in Tools › Claude Code,
 * shared by every connected site). Claude lists and searches them by name instead of hunting through the disk,
 * and every lc_html_template_* tool accepts a library name in place of a path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_TPL_LIB_TTL = 600;

function lccb_tpl_library_file() {
	return lccb_support_dir() . '/templates.json';
}

function lccb_tpl_library_config() {
	$f = lccb_tpl_library_file();
	$j = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
	$j = is_array( $j ) ? $j : array();
	foreach ( array( 'dirs', 'hidden' ) as $k ) {
		$j[ $k ] = isset( $j[ $k ] ) && is_array( $j[ $k ] ) ? array_values( array_filter( $j[ $k ], 'is_string' ) ) : array();
	}
	return $j;
}

function lccb_tpl_library_write( array $config ) {
	wp_mkdir_p( lccb_support_dir() );
	file_put_contents( lccb_tpl_library_file(), wp_json_encode( array( 'dirs' => array_values( $config['dirs'] ), 'hidden' => array_values( array_unique( $config['hidden'] ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	delete_transient( 'lccb_tpl_lib' );
}

/** @return string[] absolute folder (or zip) paths */
function lccb_tpl_library_dirs() {
	return lccb_tpl_library_config()['dirs'];
}

/** Hide one discovered template from Claude (or show it again). Its files are never touched. */
function lccb_tpl_library_hide( $path, $hide ) {
	$config = lccb_tpl_library_config();
	$config['hidden'] = $hide ? array_merge( $config['hidden'], array( (string) $path ) ) : array_values( array_diff( $config['hidden'], array( (string) $path ) ) );
	lccb_tpl_library_write( $config );
}

/** Validate and save. @return array{saved: string[], rejected: string[]} */
function lccb_tpl_library_save( array $paths ) {
	$home  = lccb_home_dir();
	$saved = array();
	$bad   = array();
	foreach ( $paths as $p ) {
		$p = trim( (string) $p );
		if ( '' === $p ) {
			continue;
		}
		$full = 0 === strpos( $p, '~/' ) ? $home . substr( $p, 1 ) : $p;
		$real = realpath( $full );
		if ( $real && 0 === strpos( $real, $home . '/' ) && ( is_dir( $real ) || preg_match( '/\.zip$/i', $real ) ) ) {
			$saved[] = $real;
		} else {
			$bad[] = $p;
		}
	}
	$config         = lccb_tpl_library_config();
	$config['dirs'] = array_values( array_unique( $saved ) );
	lccb_tpl_library_write( $config );
	return array( 'saved' => $saved, 'rejected' => $bad );
}

/** Is this folder an HTML template? Its HTML folder (an index*.html within 3 levels), or null. */
function lccb_tpl_lib_probe_dir( $dir ) {
	if ( is_file( $dir . '/wp-config.php' ) || is_file( $dir . '/composer.json' ) || is_file( $dir . '/artisan' ) ) {
		return null; // a WordPress site or PHP app
	}
	$root = lccb_tpl_html_root( $dir );
	if ( ! $root || substr_count( substr( $root, strlen( $dir ) ), '/' ) > 3 ) {
		return null;
	}
	return glob( $root . '/index*.htm*' ) && count( (array) glob( $root . '/*.htm*' ) ) >= 2 ? $root : null;
}

function lccb_tpl_lib_zip_pages( $zip ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return 0;
	}
	$za = new ZipArchive();
	if ( true !== $za->open( $zip ) ) {
		return 0;
	}
	$n     = 0;
	$php   = 0;
	$index = false;
	for ( $i = 0; $i < min( $za->numFiles, 60000 ); $i++ ) {
		$name = (string) $za->getNameIndex( $i );
		if ( preg_match( '#(^|/)(wp-config\.php|wp-content/|installer\.php$|database\.sql)#i', $name ) ) {
			$za->close();
			return 0; // a site backup, not a template
		}
		if ( preg_match( '/\.php$/i', $name ) && ! preg_match( '#(^|/)(vendor|php|forms?|ajax)/#i', $name ) ) {
			$php++;
		}
		if ( preg_match( '/\.html?$/i', $name ) && ! preg_match( '#(^|/)(__MACOSX|node_modules|documentation|docs|vendor)/#i', $name ) ) {
			$n++;
			$index = $index || (bool) preg_match( '#(^|/)index[^/]*\.html?$#i', $name );
		}
	}
	$za->close();
	return $index && $n >= 2 && $php < $n ? $n : 0; // a PHP app isn't an HTML template
}

/** Templates Claude can use: the library minus the ones the user removed. */
function lccb_tpl_library( $refresh = false ) {
	$hidden = array_flip( lccb_tpl_library_config()['hidden'] );
	return array_values( array_filter( lccb_tpl_library_all( $refresh ), function ( $t ) use ( $hidden ) {
		return ! isset( $hidden[ $t['path'] ] );
	} ) );
}

/** Templates the user removed from the library (still on disk). */
function lccb_tpl_library_removed() {
	$hidden = array_flip( lccb_tpl_library_config()['hidden'] );
	return array_values( array_filter( lccb_tpl_library_all(), function ( $t ) use ( $hidden ) {
		return isset( $hidden[ $t['path'] ] );
	} ) );
}

/**
 * Every template found in the library folders: registered folders that are templates themselves, plus their
 * sub-folders and zips that are. Cached for 10 minutes.
 */
function lccb_tpl_library_all( $refresh = false ) {
	$dirs = lccb_tpl_library_dirs();
	$key  = md5( wp_json_encode( $dirs ) );
	$hit  = $refresh ? false : get_transient( 'lccb_tpl_lib' );
	if ( is_array( $hit ) && $hit['key'] === $key ) {
		return $hit['templates'];
	}
	$out  = array();
	$add  = function ( $path, $kind, $root, $pages ) use ( &$out ) {
		$slug = lccb_tpl_slug( $path );
		$out[] = array( 'name' => $slug, 'path' => $path, 'kind' => $kind, 'html_folder' => $root, 'pages' => $pages );
	};
	$count = function ( $root ) {
		return count( array_filter( lccb_tpl_walk( $root, 3 ), function ( $f ) {
			return (bool) preg_match( '/\.html?$/i', $f );
		} ) );
	};
	foreach ( $dirs as $d ) {
		if ( is_file( $d ) ) {
			$n = lccb_tpl_lib_zip_pages( $d );
			if ( $n ) {
				$add( $d, 'zip', null, $n );
			}
			continue;
		}
		// Its sub-folders and zips that are templates; if there's only one (or none), maybe the folder itself is one.
		$kids = array();
		foreach ( (array) @scandir( $d ) as $it ) {
			if ( '' === $it || '.' === $it[0] || in_array( strtolower( $it ), array( 'node_modules', 'vendor', 'library', 'documentation', 'docs' ), true ) ) {
				continue;
			}
			$p = $d . '/' . $it;
			if ( is_dir( $p ) ) {
				$root = lccb_tpl_lib_probe_dir( $p );
				if ( $root ) {
					$kids[] = array( $p, 'folder', $root, $count( $root ) );
				}
			} elseif ( preg_match( '/\.zip$/i', $it ) && filesize( $p ) < 2048 * 1024 * 1024 ) {
				$n = lccb_tpl_lib_zip_pages( $p );
				if ( $n ) {
					$kids[] = array( $p, 'zip', null, $n );
				}
			}
		}
		$self = count( $kids ) < 2 ? lccb_tpl_lib_probe_dir( $d ) : null;
		if ( $self && substr_count( substr( $self, strlen( $d ) ), '/' ) <= 1 ) {
			$add( $d, 'folder', $self, $count( $self ) );
			continue;
		}
		foreach ( $kids as $k ) {
			$add( $k[0], $k[1], $k[2], $k[3] );
		}
	}
	// The same template as a folder and as its zip: keep the folder (no extraction needed), mention the zip.
	$names = array();
	foreach ( $out as $i => $t ) {
		if ( 'zip' === $t['kind'] ) {
			foreach ( $out as $o ) {
				if ( 'folder' === $o['kind'] && $o['name'] === $t['name'] ) {
					unset( $out[ $i ] );
					break;
				}
			}
		}
	}
	$out = array_values( $out );
	// Unique names (two downloads of the same template get -2, -3…).
	foreach ( $out as $i => $t ) {
		$base = $t['name'];
		$n    = 1;
		while ( isset( $names[ $out[ $i ]['name'] ] ) ) {
			$out[ $i ]['name'] = $base . '-' . ( ++$n );
		}
		$names[ $out[ $i ]['name'] ] = true;
	}
	set_transient( 'lccb_tpl_lib', array( 'key' => $key, 'templates' => $out ), LCCB_TPL_LIB_TTL );
	return $out;
}

/** The library name for a template path, if it's in the library. */
function lccb_tpl_library_name_for( $path ) {
	foreach ( lccb_tpl_library() as $t ) {
		if ( $t['path'] === $path ) {
			return $t['name'];
		}
	}
	return null;
}

/** A library template by name (exact, then unique partial match). @return string|null its path */
function lccb_tpl_library_lookup( $name ) {
	$name = strtolower( trim( $name ) );
	$lib  = lccb_tpl_library();
	foreach ( $lib as $t ) {
		if ( $t['name'] === $name || strtolower( basename( $t['path'] ) ) === $name ) {
			return $t['path'];
		}
	}
	$words = array_filter( preg_split( '/[\s_-]+/', $name ) );
	$hits  = array_values( array_filter( $lib, function ( $t ) use ( $words ) {
		foreach ( $words as $w ) {
			if ( false === strpos( $t['name'], $w ) ) {
				return false;
			}
		}
		return true;
	} ) );
	if ( 1 === count( $hits ) ) {
		return $hits[0]['path'];
	}
	// Copies of one template (name, name-2, name-3): the original wins.
	$bases = array_unique( array_map( function ( $t ) {
		return preg_replace( '/-\d+$/', '', $t['name'] );
	}, $hits ) );
	if ( 1 === count( $bases ) ) {
		foreach ( $hits as $t ) {
			if ( $t['name'] === $bases[0] ) {
				return $t['path'];
			}
		}
	}
	if ( $hits ) {
		throw new Exception( "\"$name\" matches several templates: " . implode( ', ', wp_list_pluck( $hits, 'name' ) ) . '. Use the full name.' );
	}
	if ( ! $lib ) {
		throw new Exception( 'No template library is set up. Ask the user to add their template folder(s) in WordPress › Tools › Claude Code › Template library, or pass the folder/zip path.' );
	}
	throw new Exception( "No template called \"$name\" in the library. Templates: " . implode( ', ', wp_list_pluck( $lib, 'name' ) ) . '.' );
}

function lccb_op_html_templates( array $args ) {
	$lib  = lccb_tpl_library( ! empty( $args['refresh'] ) );
	$find = isset( $args['find'] ) ? strtolower( trim( (string) $args['find'] ) ) : '';
	$home = lccb_home_dir();
	$tidy = function ( $p ) use ( $home ) {
		return $p && 0 === strpos( $p, $home . '/' ) ? '~' . substr( $p, strlen( $home ) ) : $p;
	};
	if ( '' === $find ) {
		return array(
			'library'   => array_map( $tidy, lccb_tpl_library_dirs() ),
			'templates' => array_map( function ( $t ) use ( $tidy ) {
				return array( 'name' => $t['name'], 'kind' => $t['kind'], 'pages' => $t['pages'], 'path' => $tidy( $t['path'] ) );
			}, $lib ),
			'next'      => $lib ? 'Pass a template\'s name as `path` to lc_html_template_scan / read / assets / preview and lc_compare. Use find to search page names across all of them.' : 'Empty: ask the user to add the folder(s) where they keep templates in WordPress › Tools › Claude Code › Template library.',
		);
	}
	// Search page names (and titles of matching pages) across the library.
	$words   = array_filter( preg_split( '/[\s_]+/', $find ) );
	$matches = array();
	foreach ( $lib as $t ) {
		$pages = array();
		if ( 'zip' === $t['kind'] ) {
			$za = new ZipArchive();
			if ( true === $za->open( $t['path'] ) ) {
				$names = array();
				$dirs  = array();
				for ( $i = 0; $i < $za->numFiles; $i++ ) {
					$n = (string) $za->getNameIndex( $i );
					if ( preg_match( '/\.html?$/i', $n ) && false === stripos( $n, '__MACOSX' ) ) {
						$names[]              = $n;
						$dirs[ dirname( $n ) ] = ( isset( $dirs[ dirname( $n ) ] ) ? $dirs[ dirname( $n ) ] : 0 ) + 1;
					}
				}
				$za->close();
				arsort( $dirs );
				$top = (string) key( $dirs ); // the zip's HTML folder: page paths are relative to it, as the other tools expect
				foreach ( $names as $n ) {
					if ( '.' === $top || 0 === strpos( $n, $top . '/' ) ) {
						$pages[] = '.' === $top ? $n : substr( $n, strlen( $top ) + 1 );
					}
				}
			}
		} else {
			foreach ( lccb_tpl_walk( $t['html_folder'], 3 ) as $f ) {
				$pages[] = substr( $f, strlen( $t['html_folder'] ) + 1 );
			}
		}
		$hit = array();
		foreach ( $pages as $p ) {
			if ( ! preg_match( '/\.html?$/i', $p ) ) {
				continue;
			}
			$hay = strtolower( $t['name'] . ' ' . $p );
			$all = true;
			foreach ( $words as $w ) {
				if ( false === strpos( $hay, $w ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				$hit[] = $p;
			}
		}
		if ( $hit ) {
			sort( $hit );
			$matches[] = array( 'template' => $t['name'], 'pages' => array_slice( $hit, 0, 40 ), 'total' => count( $hit ) );
		}
	}
	return array(
		'find'    => $find,
		'matches' => $matches,
		'next'    => $matches ? 'lc_html_template_scan {path: "<template>", filter: "<page group>"} for details, or lc_html_template_read {path, page} for a page\'s sections.' : 'Nothing matched. lc_html_templates without find lists the templates.',
	);
}
