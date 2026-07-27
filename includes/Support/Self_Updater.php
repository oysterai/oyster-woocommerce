<?php
/**
 * Self-update via GitHub Releases.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * This plugin isn't (yet) distributed through wordpress.org, so WordPress
 * core has no built-in way to know a newer version exists — normally that's
 * exactly what the wordpress.org update-check API provides for every hosted
 * plugin. Plugin Update Checker (vendored at lib/plugin-update-checker/,
 * MIT-licensed, no Composer involved — same dependency-free approach as the
 * rest of this plugin) fills that gap by hooking the same core update
 * machinery a wordpress.org-hosted plugin would use, but pointed at this
 * repo's GitHub Releases instead.
 */
final class Self_Updater {

	private const REPO_URL = 'https://github.com/oysterai/oyster-woocommerce/';

	private const SLUG = 'oyster-woocommerce';

	public static function register(): void {
		require_once OYSTER_WOO_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPO_URL, OYSTER_WOO_FILE, self::SLUG );

		// Fetch the zip WE build and attach to each release
		// (bin/build-release-zip.sh, run by .github/workflows/release.yml)
		// rather than GitHub's auto-generated "Source code" archive, which
		// would ship dev-only files (bin/, callouts.md, .github/) straight
		// to every merchant's site.
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
