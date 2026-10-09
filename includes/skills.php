<?php
/**
 * Packaged Claude Code skills (/kickoff, /from-template, /migrate, /responsive, /images, /qa, /launch, /handover):
 * copied from the plugin's skills/ folder into the site's .claude/skills/ on Connect, refreshed on every Connect,
 * removed on Disconnect and cleanup. Each installed copy carries a marker file; a skill without it is the user's own
 * and is never overwritten or removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_SKILL_MARKER = '.lccb-managed';

function lccb_skills_source() {
	return LCCB_DIR . 'skills';
}

function lccb_skills_target() {
	return lccb_site_root() . '/.claude/skills';
}

/** @return string[] names of the skills this plugin ships */
function lccb_skills_shipped() {
	$out = array();
	foreach ( (array) glob( lccb_skills_source() . '/*/SKILL.md' ) as $f ) {
		$out[] = basename( dirname( $f ) );
	}
	return $out;
}

/** Install or refresh. @return array{installed: string[], skipped: string[]} */
function lccb_skills_install() {
	$installed = array();
	$skipped   = array();
	foreach ( lccb_skills_shipped() as $name ) {
		$dest = lccb_skills_target() . '/' . $name;
		if ( is_dir( $dest ) && ! is_file( $dest . '/' . LCCB_SKILL_MARKER ) ) {
			$skipped[] = $name; // the user's own skill with the same name
			continue;
		}
		wp_mkdir_p( $dest );
		$ok = copy( lccb_skills_source() . '/' . $name . '/SKILL.md', $dest . '/SKILL.md' );
		file_put_contents( $dest . '/' . LCCB_SKILL_MARKER, "Installed by LC Claude Bridge " . LCCB_VERSION . ". Refreshed on Connect and removed on Disconnect: edit a copy under another name if you want your own version.\n" );
		if ( $ok ) {
			$installed[] = $name;
		}
	}
	// Skills a previous version installed but this one no longer ships.
	foreach ( (array) glob( lccb_skills_target() . '/*/' . LCCB_SKILL_MARKER ) as $marker ) {
		$name = basename( dirname( $marker ) );
		if ( ! in_array( $name, lccb_skills_shipped(), true ) ) {
			lccb_skills_remove_one( dirname( $marker ) );
		}
	}
	return array( 'installed' => $installed, 'skipped' => $skipped );
}

function lccb_skills_remove_one( $dir ) {
	foreach ( array( 'SKILL.md', LCCB_SKILL_MARKER ) as $f ) {
		@unlink( $dir . '/' . $f );
	}
	@rmdir( $dir ); // only if nothing else is in it
}

/** Remove the managed skills (and the skills folder if that leaves it empty). @return int how many */
function lccb_skills_remove() {
	$n = 0;
	foreach ( (array) glob( lccb_skills_target() . '/*/' . LCCB_SKILL_MARKER ) as $marker ) {
		lccb_skills_remove_one( dirname( $marker ) );
		$n++;
	}
	@rmdir( lccb_skills_target() );
	return $n;
}
